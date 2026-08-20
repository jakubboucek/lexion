<?php declare(strict_types=1);

namespace App\Presentation\System\Sync;

use App\Model\CaseFile\CaseFileRepository;
use App\Model\Sync\CodelistDifference;
use App\Model\Sync\CodelistDifferenceKind;
use App\Model\Sync\GzipWriter;
use App\Model\Sync\SyncException;
use App\Model\Sync\SyncExportService;
use App\Model\Sync\SyncFormat;
use App\Model\Sync\SyncImportReport;
use App\Model\Sync\SyncImportService;
use App\Model\Sync\SyncProblem;
use App\Model\Sync\SyncProblemReason;
use App\Presentation\System\BasePresenter;
use Nette\Application\Responses\CallbackResponse;
use Nette\Application\UI\Form;
use Nette\Http\FileUpload;
use Nette\Http\IRequest;
use Nette\Http\IResponse;


/**
 * One-way, additive data sync between environments (see the Model\Sync
 * domain). One side exports a JSONL file, the other uploads it here; the
 * merge never deletes and never overwrites newer data with older, so the
 * direction is just a matter of which side is doing the uploading.
 *
 * The export is offered in parts because the receiving side takes the file
 * through an HTTP upload, and hosting upload limits are far below the size of
 * the whole records.
 */
final class SyncPresenter extends BasePresenter
{
    /** Bounds for the part size the operator may ask for. */
    private const int MinPartSize = 100;
    private const int MaxPartSize = 20000;

    /** Part sizes offered on the export page. */
    private const array PartSizes = [1000, 5000, 20000];

    private ?SyncImportReport $report = null;


    public function __construct(
        private readonly SyncExportService $export,
        private readonly SyncImportService $import,
        private readonly CaseFileRepository $caseFiles,
    ) {
        parent::__construct();
    }


    public function renderExport(int $size = SyncFormat::DefaultPartSize): void
    {
        $size = max(self::MinPartSize, min(self::MaxPartSize, $size));
        $this->template->partSize = $size;
        $this->template->partSizes = self::PartSizes;
        $this->template->parts = $this->export->parts($size);
        $this->template->caseFiles = $this->caseFiles->countAll();
        $this->template->version = SyncFormat::Version;
    }


    /**
     * Streams one part. The id range comes from the export page rather than an
     * offset, so the slice stays the same even while records are being added.
     */
    public function actionDownload(int $part, int $parts, int $from, int $to): void
    {
        $generatedAt = new \DateTimeImmutable;
        $origin = $this->getHttpRequest()->getUrl()->getHost();
        $fileName = SyncFormat::fileName($generatedAt, $part, $parts);

        $this->sendResponse(new CallbackResponse(
            function (IRequest $httpRequest, IResponse $httpResponse) use (
                $part, $parts, $from, $to, $origin, $generatedAt, $fileName,
            ): void {
                $httpResponse->setContentType(SyncFormat::ContentType);
                $httpResponse->setHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"');

                $gzip = new GzipWriter(static function (string $chunk): void {
                    echo $chunk;
                });
                $this->export->writePart($gzip->write(...), $part, $parts, $from, $to, $origin, $generatedAt);
                $gzip->finish();
            },
        ));
    }


    public function renderImport(): void
    {
        $this->template->report = $this->report;
        $this->template->problems = $this->report !== null
            ? array_map(self::problemView(...), $this->report->problems)
            : [];
        $this->template->codelistDifferences = $this->report !== null
            ? array_map(self::differenceView(...), $this->report->codelistDifferences)
            : [];
        $this->template->uploadLimit = ini_get('upload_max_filesize');
        $this->template->postLimit = ini_get('post_max_size');
        // A POST that blew past post_max_size arrives with everything stripped,
        // so the form never even sees a submit - say so instead of rendering an
        // empty page that looks like nothing happened.
        $this->template->tooLarge = $this->getHttpRequest()->isMethod('POST')
            && $this->getHttpRequest()->getPost() === []
            && $this->getHttpRequest()->getFiles() === [];
    }


    protected function createComponentImportForm(): Form
    {
        $form = new Form;
        $form->addUpload('file', 'Synchronizační soubor (.jsonl.gz)')
            ->setRequired('Vyberte soubor k importu.');
        $form->addSubmit('send', 'Spustit import');
        $form->onSuccess[] = $this->importFormSucceeded(...);
        return $form;
    }


    private function importFormSucceeded(Form $form, \stdClass $data): void
    {
        $file = $data->file;
        assert($file instanceof FileUpload);
        if (!$file->isOk()) {
            $form->addError('Soubor se nepodařilo nahrát - nejspíš překročil povolenou velikost.');
            return;
        }

        // A full import walks tens of thousands of rows; the default web time
        // limit is not meant for that.
        @set_time_limit(0);

        try {
            // No redirect afterwards: the report exists only here and now, and
            // re-running the import to see it again would be the wrong cure.
            $this->report = $this->import->import($file->getTemporaryFile());
        } catch (SyncException $e) {
            $form->addError($e->getMessage());
        }
    }


    /** @return array{caseFile: string, reason: string, detail: string|null} */
    private static function problemView(SyncProblem $problem): array
    {
        return [
            'caseFile' => $problem->caseFile,
            'reason' => match ($problem->reason) {
                SyncProblemReason::EventMissingInNewerSnapshot
                    => 'novější verze spisu neobsahuje událost, kterou starší zná (podezření na přečíslování)',
                SyncProblemReason::EventDateMismatch
                    => 'spárované události mají různé datum (podezření na přečíslování)',
                SyncProblemReason::UnknownCodelistKey
                    => 'odkazuje na položku číselníku, kterou tady nemáme',
                SyncProblemReason::InvalidRecord => 'záznam v souboru je poškozený',
            },
            'detail' => $problem->detail,
        ];
    }


    /** @return array{codelist: string, key: string, kind: string} */
    private static function differenceView(CodelistDifference $difference): array
    {
        return [
            'codelist' => $difference->codelist,
            'key' => $difference->key,
            'kind' => match ($difference->kind) {
                CodelistDifferenceKind::MissingLocally => 'je v souboru, chybí tady',
                CodelistDifferenceKind::MissingInFile => 'je tady, chybí v souboru',
                CodelistDifferenceKind::Differs => 'liší se obsahem',
            },
        ];
    }
}
