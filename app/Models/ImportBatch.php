<?php

namespace App\Models;

use App\Services\Import\ImportService;
use App\Services\Import\Processing\ImportResult;
use App\Services\Import\Source\SourceTable;
use App\Services\Import\Template\TemplateSchema;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @phpstan-import-type TemplateSchemaArray from TemplateSchema
 * @phpstan-import-type SourceTableSummary from SourceTable
 * @phpstan-import-type ImportStats from ImportResult
 * @phpstan-import-type CompactRow from ImportService
 * @phpstan-import-type StoredMappingEntry from ImportService
 * @phpstan-import-type ImportSettings from ImportService
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $status
 * @property string|null $category
 * @property string|null $cliente
 * @property string $source_name
 * @property string $template_name
 * @property string $source_path
 * @property string $template_path
 * @property TemplateSchemaArray|null $template_schema
 * @property list<SourceTableSummary>|null $source_tables
 * @property array<string, StoredMappingEntry>|null $mapping
 * @property ImportSettings|null $settings
 * @property (ImportStats&array{notes: list<string>})|null $stats
 * @property list<CompactRow>|null $result_rows
 * @property string|null $result_path
 * @property string|null $clean_path
 * @property string|null $error
 * @property Carbon|null $processed_at
 * @property Carbon $created_at
 */
#[Fillable([
    'user_id', 'status', 'category', 'cliente', 'source_name', 'template_name', 'source_path', 'template_path',
    'template_schema', 'source_tables', 'mapping', 'settings', 'stats', 'result_rows', 'result_path',
    'clean_path', 'error', 'processed_at',
])]
class ImportBatch extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'template_schema' => 'array',
            'source_tables' => 'array',
            'mapping' => 'array',
            'settings' => 'array',
            'stats' => 'array',
            'result_rows' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schema(): TemplateSchema
    {
        return TemplateSchema::fromArray($this->template_schema ?? throw new RuntimeException('El lote no tiene una plantilla analizada.'));
    }

    public function directory(): string
    {
        return 'imports/'.$this->id;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }
}
