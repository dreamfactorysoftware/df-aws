<?php

namespace DreamFactory\Core\Aws\Database\Query\Processors;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\PostgresProcessor;

class RedshiftProcessor extends PostgresProcessor
{
    /**
     * Process an "insert get ID" query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $sql
     * @param  array   $values
     * @param  string  $sequence
     * @return int
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        // Redshift doesn't support "returning" ids, must select last inserted.
        $query->getConnection()->statement($sql, $values);

        $sequence = $sequence ?: 'id';

        $result = $query->getConnection()->select("SELECT MAX($sequence) AS $sequence from {$query->from};")[0];
        $id = is_object($result) ? $result->$sequence : $result[$sequence];

        return is_numeric($id) ? (int) $id : $id;
    }
}
