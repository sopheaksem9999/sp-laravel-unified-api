<?php

namespace Sopheak\Core\Services;

use Exception;
use Illuminate\Http\Request;

class AttachmentAccessService
{
    public function shouldValidateFolders(): bool
    {
        return (bool) config('attachments.access.validate_folder_exists', false);
    }

    public function folderExists(?string $folderId, mixed $tenantId = null): bool
    {
        if (null === $folderId || '' === trim($folderId)) {
            return true;
        }

        if (!$this->shouldValidateFolders()) {
            return true;
        }

        return [] !== $this->findRecord('sp_document_folders', $folderId, $tenantId);
    }

    public function shouldValidateTargetRecords(): bool
    {
        return (bool) config('attachments.access.validate_record_exists', false);
    }

    public function targetRecordExists(?string $table, mixed $recordId, mixed $tenantId = null): bool
    {
        if (!$this->shouldValidateTargetRecords()) {
            return true;
        }

        if (!is_string($table) || '' === trim($table) || null === $recordId || '' === (string) $recordId) {
            return false;
        }

        if ([] === RecordConfigService::table($table)) {
            return !(bool) config('attachments.access.fail_unknown_record_tables', false);
        }

        return [] !== $this->findRecord($table, (string) $recordId, $tenantId);
    }

    public function targetRecordAuthorized(Request $request, string $action, ?string $table, mixed $recordId, mixed $tenantId = null): bool
    {
        $authorizer = config('attachments.access.record_authorizer');
        if (!is_callable($authorizer)) {
            return true;
        }

        return (bool) $authorizer($request, $action, $table, $recordId, $tenantId);
    }

    public function canDeleteFolder(string $folderId, mixed $tenantId = null): bool
    {
        if ('restrict' !== (string) config('attachments.folder_delete_strategy', 'legacy')) {
            return true;
        }

        return 0 === $this->countRecords('sp_document_folders', ['parent_id' => 'eq.' . $folderId], $tenantId)
            && 0 === $this->countRecords('sp_attachments', ['folder_id' => 'eq.' . $folderId], $tenantId);
    }

    public function attachmentHasOtherLinks(string $attachmentId, int|string|null $excludingLinkId, mixed $tenantId = null): bool
    {
        $links = RecordService::executeGetByFilter('sp_attachment_links', [
            'attachment_id' => 'eq.' . $attachmentId,
        ], $tenantId);

        foreach ($this->recordsFromResult($links) as $link) {
            $linkId = $link['id'] ?? null;
            if (null === $excludingLinkId || (string) $linkId !== (string) $excludingLinkId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function findRecord(string $table, string $id, mixed $tenantId): array
    {
        try {
            $result = RecordService::executeGetById(
                table: $table,
                id: $id,
                queryParams: [],
                tenantId: $tenantId
            );
        } catch (Exception) {
            return [];
        }

        return $this->recordFromResult($result);
    }

    /**
     * @param array<string, string> $filters
     */
    private function countRecords(string $table, array $filters, mixed $tenantId): int
    {
        try {
            $result = RecordService::executeGetByFilter($table, $filters, $tenantId);
        } catch (Exception) {
            return 0;
        }

        return count($this->recordsFromResult($result));
    }

    /**
     * @return array<string, mixed>
     */
    private function recordFromResult(mixed $result): array
    {
        if (is_array($result) && array_key_exists('data', $result)) {
            $data = $result['data'];

            if (is_array($data)) {
                return $data;
            }

            if (is_object($data)) {
                return (array) $data;
            }
        }

        if (is_object($result)) {
            return (array) $result;
        }

        return is_array($result) ? $result : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recordsFromResult(mixed $result): array
    {
        $data = is_array($result) && array_key_exists('data', $result) ? $result['data'] : $result;
        if (!is_array($data)) {
            return [];
        }

        $records = [];
        foreach ($data as $record) {
            if (is_array($record)) {
                $records[] = $record;
            } elseif (is_object($record)) {
                $records[] = (array) $record;
            }
        }

        return $records;
    }
}
