<?php declare(strict_types=1);

namespace App\Model\CaseFile;


/**
 * Data sources feeding the case file records. The backing value is used as-is
 * in per-source column names (infosoud_json/infosoud_at, ...) and in the
 * `source` column of the projection tables.
 */
enum DataSource: string
{
    case Infosoud = 'infosoud';
    case Isir = 'isir';


    public function jsonColumn(): string
    {
        return $this->value . '_json';
    }


    public function atColumn(): string
    {
        return $this->value . '_at';
    }
}
