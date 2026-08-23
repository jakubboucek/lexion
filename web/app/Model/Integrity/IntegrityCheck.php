<?php declare(strict_types=1);

namespace App\Model\Integrity;


/**
 * One declarative integrity check: a stable slug (referenced from logs and
 * repair tooling), a category, Czech UI texts and the SQL that measures it.
 *
 * The check is data, not behavior - IntegrityService runs the SQL. The
 * queries are read-only by contract; a check must never repair anything.
 */
final readonly class IntegrityCheck
{
    public function __construct(
        /** Stable machine name, e.g. `event-projection-count`. */
        public string $slug,
        public IntegrityCategory $category,
        /** Czech UI title, e.g. "Projekce událostí nesedí s raw JSON". */
        public string $title,
        /** Czech UI description: what the check means and, for a nonzero count, what to do. */
        public string $description,
        /**
         * Query returning a single scalar - the number of offending rows.
         * literal-string: the SQL is part of the declaration, never input.
         *
         * @var literal-string
         */
        public string $countSql,
        /**
         * Query returning one text column `sample` with a few example rows
         * (LIMIT belongs in the query), or null when samples make no sense.
         *
         * @var literal-string|null
         */
        public ?string $samplesSql = null,
    ) {
    }
}
