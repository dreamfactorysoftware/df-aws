<?php

namespace DreamFactory\Core\Aws\Database;

use Doctrine\DBAL\Driver\PDOPgSql\Driver as DoctrineDriver;
use DreamFactory\Core\Aws\Database\Schema\RedshiftBuilder;
use DreamFactory\Core\Aws\Database\Query\Processors\RedshiftProcessor;
use DreamFactory\Core\Aws\Database\Query\Grammars\RedshiftGrammar as QueryGrammar;
use DreamFactory\Core\Aws\Database\Schema\Grammars\RedshiftGrammar as SchemaGrammar;
use Illuminate\Database\Connection;

class RedshiftConnection extends Connection
{
    /**
     * Get a schema builder instance for the connection.
     *
     * @return RedshiftBuilder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new RedshiftBuilder($this);
    }

    /**
     * Get the default query grammar instance.
     *
     * @return QueryGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return $this->withTablePrefix(new QueryGrammar);
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return SchemaGrammar
     */
    protected function getDefaultSchemaGrammar()
    {
        return $this->withTablePrefix(new SchemaGrammar);
    }

    /**
     * Get the default post processor instance.
     *
     * @return RedshiftProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new RedshiftProcessor;
    }

    /**
     * Get the Doctrine DBAL driver.
     *
     * @return \Doctrine\DBAL\Driver\PDOPgSql\Driver
     */
    protected function getDoctrineDriver()
    {
        return new DoctrineDriver;
    }
}
