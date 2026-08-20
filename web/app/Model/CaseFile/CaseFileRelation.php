<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use JakubBoucek\Hydrator\Entity;


/**
 * A directed relation between two case files (table `proceeding_relation`, see
 * migration 2026-07-19-04). Both endpoints are the case identity tuple instead
 * of a FK: the other side may not be loaded at all, and a PRED_VEC reference
 * may not even be a court case (a prosecutor file), in which case
 * `dstCourtKod` stays NULL.
 *
 * The entity already carries the target name of the domain (`CaseFile`) while
 * the table keeps the old one - the DB rename is a separate coordinated wave
 * (see CLAUDE.md, *Terminologie a pojmenování*).
 *
 * `dst_court_key` has no property here: the database generates it from
 * `dst_court_kod` so the unique key can span a nullable column.
 */
class CaseFileRelation implements Entity
{
    public int $id;
    public string $srcCourtKod;
    public string $srcRegistryNorm;
    public int $srcSenate;
    public int $srcBcNumber;
    public int $srcYear;
    /** NULL when the target's court is unknown (court-less reference). */
    public ?string $dstCourtKod;
    public string $dstRegistryNorm;
    public int $dstSenate;
    public int $dstBcNumber;
    public int $dstYear;
    /**
     * Code of the relation_type codelist. Stays a string for the same reason
     * as RelationTypeEntry::$code - the codelist is editable, so a code
     * outside the RelationType enum is a legitimate row.
     */
    public string $relationType;
    /**
     * Which data source produced the row: 'infosoud' rows are rebuilt by every
     * projection run, 'manual' ones always survive. Not typed as DataSource -
     * that enum describes the upstream feeds of a case file and has no
     * 'manual' case, while this column explicitly allows it.
     */
    public string $source;
    public ?string $note;
    public \DateTimeImmutable $createdAt;
    public \DateTimeImmutable $updatedAt;
}
