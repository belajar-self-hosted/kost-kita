<?php

namespace App\Support;

use App\Models\CashTransaction;
use App\Models\DepositClaimResolution;
use App\Models\DepositEvidence;
use App\Models\DepositSettlementLine;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DepositEvidenceService
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param list<UploadedFile> $files */
    public function attachToLine(Tenant $tenant, User $actor, int $lineId, array $files): void
    {
        $line = DepositSettlementLine::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($lineId)
            ->firstOrFail();
        $this->saveFiles($tenant, $actor, $files, ['settlement_line_id' => $line->id, 'claim_resolution_id' => null], "{$line->settlement_id}/lines/{$line->id}");
    }

    /** @param list<UploadedFile> $files */
    public function attachToResolution(Tenant $tenant, User $actor, int $resolutionId, array $files): void
    {
        $resolution = DepositClaimResolution::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($resolutionId)
            ->firstOrFail();
        $this->saveFiles($tenant, $actor, $files, ['settlement_line_id' => null, 'claim_resolution_id' => $resolution->id], "{$resolution->settlement_id}/resolutions/{$resolution->id}");
    }

    /** @param list<UploadedFile> $files
     * @return Collection<int, DepositEvidence>
     */
    public function attachToCashTransaction(Tenant $tenant, User $actor, int $cashTransactionId, array $files): Collection
    {
        $transaction = CashTransaction::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($cashTransactionId)
            ->lockForUpdate()
            ->firstOrFail();
        $this->saveFiles($tenant, $actor, $files, [
            'settlement_line_id' => null,
            'claim_resolution_id' => null,
            'cash_transaction_id' => $transaction->id,
        ], "cash/{$transaction->id}");

        return DepositEvidence::query()
            ->where('tenant_id', $tenant->id)
            ->where('cash_transaction_id', $transaction->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<mixed>  $files
     * @param  array{settlement_line_id: int|null, claim_resolution_id: int|null, cash_transaction_id?: int|null}  $parent
     */
    private function saveFiles(Tenant $tenant, User $actor, array $files, array $parent, string $scope): void
    {
        if ($files === []) {
            return;
        }

        $storedKeys = [];
        try {
            DB::transaction(function () use ($tenant, $actor, $files, $parent, $scope, &$storedKeys): void {
                foreach ($files as $file) {
                    if (! $file instanceof UploadedFile || ! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true) || $file->getSize() > 10 * 1024 * 1024) {
                        throw ValidationException::withMessages(['evidence' => 'Bukti harus berupa PDF atau gambar JPG, PNG, WEBP maksimal 10 MB.']);
                    }
                    $sha = hash_file('sha256', $file->getRealPath());
                    if ($sha === false) {
                        throw ValidationException::withMessages(['evidence' => 'Checksum bukti tidak dapat dihitung.']);
                    }
                    $duplicateQuery = DepositEvidence::query()->where('tenant_id', $tenant->id)->where('sha256', $sha);
                    foreach (['settlement_line_id', 'claim_resolution_id', 'cash_transaction_id'] as $parentKey) {
                        $parentId = $parent[$parentKey] ?? null;
                        $parentId === null
                            ? $duplicateQuery->whereNull($parentKey)
                            : $duplicateQuery->where($parentKey, $parentId);
                    }
                    if ($duplicateQuery->exists()) {
                        continue;
                    }
                    $filename = basename((string) $file->getClientOriginalName());
                    $storageKey = $file->storeAs("deposit-evidence/{$tenant->id}/{$scope}", Str::uuid().'.'.$file->guessExtension(), 'local');
                    if ($storageKey === false) {
                        throw ValidationException::withMessages(['evidence' => 'Berkas bukti tidak dapat disimpan.']);
                    }
                    $storedKeys[] = $storageKey;
                    $record = DepositEvidence::query()->create([
                        'tenant_id' => $tenant->id,
                        ...$parent,
                        'storage_key' => $storageKey,
                        'original_filename' => mb_substr($filename, 0, 255),
                        'mime_type' => $file->getMimeType(),
                        'sha256' => $sha,
                        'uploaded_by' => $actor->id,
                        'created_at' => now('UTC'),
                    ]);
                    $this->audit->record($actor, $tenant, 'deposit.evidence_uploaded', 'deposit_evidence', $record->id, null, [
                        'settlement_line_id' => $parent['settlement_line_id'],
                        'claim_resolution_id' => $parent['claim_resolution_id'],
                        'cash_transaction_id' => $parent['cash_transaction_id'] ?? null,
                        'mime_type' => $file->getMimeType(),
                        'sha256' => $sha,
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($storedKeys);
            throw $exception;
        }
    }

    public function download(Tenant $tenant, User $actor, int $evidenceId): StreamedResponse
    {
        $evidence = DepositEvidence::query()->where('tenant_id', $tenant->id)->whereKey($evidenceId)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($evidence->storage_key), 404);
        $this->audit->record($actor, $tenant, 'deposit.evidence_downloaded', 'deposit_evidence', $evidence->id, null, [
            'settlement_line_id' => $evidence->settlement_line_id,
            'claim_resolution_id' => $evidence->claim_resolution_id,
            'cash_transaction_id' => $evidence->cash_transaction_id,
        ]);

        return Storage::disk('local')->download($evidence->storage_key, $evidence->original_filename, [
            'Content-Type' => $evidence->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
