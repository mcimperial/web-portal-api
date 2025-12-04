<?php

namespace App\Http\Traits;

use Modules\ClientMasterlist\App\Models\ActionLog;
use Illuminate\Support\Facades\Auth;

trait LogsActions
{
    /**
     * Log a CRUD action
     *
     * @param string $actionType CREATE, READ, UPDATE, DELETE
     * @param mixed $model The model instance or class name
     * @param array|null $oldValues Previous values (for UPDATE/DELETE)
     * @param array|null $newValues New values (for CREATE/UPDATE)
     * @param string|null $description Human-readable description
     * @param array|null $metadata Additional context
     * @param string $status success, failed, pending
     * @param string|null $errorMessage Error details if failed
     * @return ActionLog
     */
    protected function logAction(
        string $actionType,
        $model = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?array $metadata = null,
        string $status = 'success',
        ?string $errorMessage = null
    ): ActionLog {
        $user = Auth::user();
        
        // Extract model information
        $modelType = null;
        $modelId = null;
        
        if ($model) {
            if (is_object($model)) {
                $modelType = get_class($model);
                $modelId = $model->id ?? null;
            } else {
                $modelType = $model;
            }
        }

        // Get request information
        $request = request();
        
        return ActionLog::create([
            'action_type' => $actionType,
            'model_type' => $modelType,
            'model_id' => $modelId,
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'user_name' => $user?->name ?? ($user?->first_name . ' ' . $user?->last_name),
            'description' => $description ?? $this->generateDescription($actionType, $modelType, $modelId),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'status' => $status,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Generate a human-readable description
     */
    private function generateDescription(string $actionType, ?string $modelType, $modelId): string
    {
        $modelName = $modelType ? class_basename($modelType) : 'Record';
        $idText = $modelId ? " (ID: {$modelId})" : '';
        
        return match($actionType) {
            'CREATE' => "Created {$modelName}{$idText}",
            'READ' => "Viewed {$modelName}{$idText}",
            'UPDATE' => "Updated {$modelName}{$idText}",
            'DELETE' => "Deleted {$modelName}{$idText}",
            default => "{$actionType} on {$modelName}{$idText}",
        };
    }

    /**
     * Log a successful create action
     */
    protected function logCreate($model, ?array $metadata = null): ActionLog
    {
        $values = is_object($model) ? $model->toArray() : $model;
        return $this->logAction('CREATE', $model, null, $values, null, $metadata);
    }

    /**
     * Log a successful update action
     */
    protected function logUpdate($model, array $oldValues, ?array $metadata = null): ActionLog
    {
        $newValues = is_object($model) ? $model->toArray() : $model;
        return $this->logAction('UPDATE', $model, $oldValues, $newValues, null, $metadata);
    }

    /**
     * Log a successful delete action
     */
    protected function logDelete($model, ?array $metadata = null): ActionLog
    {
        $oldValues = is_object($model) ? $model->toArray() : null;
        return $this->logAction('DELETE', $model, $oldValues, null, null, $metadata);
    }

    /**
     * Log a read/view action
     */
    protected function logRead($model, ?array $metadata = null): ActionLog
    {
        return $this->logAction('READ', $model, null, null, null, $metadata);
    }

    /**
     * Log a failed action
     */
    protected function logFailedAction(
        string $actionType,
        $model,
        string $errorMessage,
        ?array $metadata = null
    ): ActionLog {
        return $this->logAction($actionType, $model, null, null, null, $metadata, 'failed', $errorMessage);
    }
}
