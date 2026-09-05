<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Applied to the models whose create/update/delete counts as a "significant
 * action" (see AuditLogger's docblock). Boots create/update/delete listeners
 * that write through AuditLogger, using the currently authenticated user as
 * actor (null for console/queued/system writes, matching AuditLogger's own
 * nullable `$actor` contract) and the model's own class name to derive the
 * action string ("order.created", "inventory_item.updated", …).
 *
 * This is the coverage *floor*: it catches every plain create/update/delete
 * automatically, so a new mutation path added later is logged without anyone
 * remembering to wire it. A handful of actions that don't reduce to one model
 * save (a stock adjustment, a status transition, a merge) additionally write
 * a richer, hand-named entry of their own — see the call sites listed in
 * AuditLogger's docblock — and use auditIgnoreOnUpdate() below to suppress the
 * generic entry for the field that richer entry already covers, so the trail
 * doesn't carry two entries for the same change.
 *
 * @mixin Model
 */
trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            $snapshot = static::auditCreateSnapshot($model);

            app(AuditLogger::class)->record(Auth::user(), static::auditAction('created'), $model, $snapshot);
        });

        static::updated(function (Model $model): void {
            $changes = collect($model->getChanges())
                ->except(['updated_at', ...static::auditIgnoreOnUpdate()])
                ->all();

            // Nothing left to report — either a no-op save, or every changed
            // field is covered by a richer explicit entry elsewhere.
            if ($changes === []) {
                return;
            }

            app(AuditLogger::class)->record(Auth::user(), static::auditAction('updated'), $model, ['changed' => $changes]);
        });

        static::deleted(function (Model $model): void {
            app(AuditLogger::class)->record(Auth::user(), static::auditAction('deleted'), $model);
        });
    }

    protected static function auditAction(string $event): string
    {
        return static::auditSubject().'.'.$event;
    }

    /**
     * The action string's subject prefix. Defaults to the model's own
     * snake-cased class name; override when two models should read as one
     * subject (Tenant and TenantSetting both report as "settings").
     */
    protected static function auditSubject(): string
    {
        return Str::snake(class_basename(static::class));
    }

    /**
     * Extra metadata captured on create. Empty by default — a model opts in
     * to a snapshot rather than the trait dumping every attribute, since not
     * every field is safe or useful to write into an audit trail unprompted.
     *
     * @return array<string, mixed>
     */
    protected static function auditCreateSnapshot(Model $model): array
    {
        return [];
    }

    /**
     * Attribute names to leave out of the "changed" metadata on update, on
     * top of `updated_at`. Use this for a field that already gets a richer,
     * hand-named entry from an explicit AuditLogger call elsewhere (e.g.
     * `status` on Order/WorkOrder, `current_stock` on InventoryItem) — not to
     * hide sensitive data, which should instead simply not be fillable.
     *
     * @return list<string>
     */
    protected static function auditIgnoreOnUpdate(): array
    {
        return [];
    }
}
