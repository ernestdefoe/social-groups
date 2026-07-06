<?php

use Illuminate\Database\Schema\Builder;

/**
 * Intentional no-op, kept for migration-history continuity.
 *
 * This step originally added sg_primary_group_id to the core users table. That
 * column was never read or written by the extension, and an ALTER TABLE on a
 * large users table can hold a metadata lock long enough to cause visible
 * downtime (CLAUDE.md §45). The column is dropped by migration 000011 and the
 * primary group now lives in the social_group_user_primary companion table, so
 * this up()/down() are deliberately empty rather than deleted — removing the
 * file would desync the migrations table on installs that already ran it. The
 * raw closure form is required: there is no Migration helper for a no-op.
 */
return [
    'up'   => function (Builder $schema) {},
    'down' => function (Builder $schema) {},
];
