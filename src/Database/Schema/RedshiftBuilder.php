<?php

namespace DreamFactory\Core\Aws\Database\Schema;

use Illuminate\Database\Schema\Builder;

class RedshiftBuilder extends Builder
{
    /**
     * Determine if the given table exists.
     *
     * @param  string  $table
     * @return bool
     */
    public function hasTable($table)
    {
        $sql = $this->grammar->compileTableExists();

        $schema = $this->connection->getConfig('schema');

        $table = $this->connection->getTablePrefix().$table;

        return count($this->connection->select($sql, [$schema, $table])) > 0;
    }
}
