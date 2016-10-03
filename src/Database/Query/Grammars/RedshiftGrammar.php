<?php

namespace DreamFactory\Core\Aws\Database\Query\Grammars;

use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Builder;

class RedshiftGrammar extends PostgresGrammar
{
   /**
     * Compile an insert and get ID statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array   $values
     * @param  string  $sequence
     * @return string
     */
    public function compileInsertGetId(Builder $query, $values, $sequence)
    {
        // Redshift does not support "returning" ids.
        return $this->compileInsert($query, $values);
    }
}
