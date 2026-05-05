<?php

namespace DreamFactory\Core\Aws\Database\Schema;

use Illuminate\Database\Schema\Builder;

/**
 * Redshift schema builder.
 *
 * Extends the base Illuminate Builder. The legacy `hasTable()` override has
 * been removed for Laravel 13 compatibility: the parent now invokes
 * `Grammar::compileTableExists($schema, $table)` with the required two
 * arguments (the L11 zero-arg form was removed), and the parent's
 * implementation already honours the connection's configured schema and
 * table prefix.
 */
class RedshiftBuilder extends Builder
{
}
