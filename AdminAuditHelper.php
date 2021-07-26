<?php

namespace App\Helpers;

use App\Models\AdminAuditLog;
use App\Models\AdminAuditRelatedLog;
use Illuminate\Database\Eloquent\Model as coreModel;
use App\Traits\AdminAudit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Class AdminAuditHelper
 *
 * Creates a readable report about admin interactions
 * as they have too much freedom to brake the data or input incorrect vehicle description, images etc.
 *
 * @package App\Helpers
 */
class AdminAuditHelper
{
    const skipFields = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];
    const availableEvents = [
        'index',
        'show',
        'create',
        'update',
        'delete',
        'downloaded',
    ];
    const sensitiveData = ['password', 'password_confirmation', 'token', 'verify_token', 'apiUrl'];

    /**
     * @param $event_type
     * @param array $changeset
     * @param null $ref_id
     * @param null $description
     * @return \Illuminate\Database\Eloquent\Model|void
     */
    public static function saveAudit($event_type, $changeset = [], $ref_id = null, $description = null)
    {
        $ref_id = intval($ref_id);
        if (!in_array($event_type, self::availableEvents)) {
            return;
        }

        if ($event_type == 'show' && !$ref_id) {
            return;
        }

        $user = auth()->user();
        $old_data = $changeset['old'] ?? [];
        $new_data = $changeset['new'] ?? [];
        if (is_array($description)) {
            $description = array_filter($description);
            $description = $description ? json_encode($description) : null;
        }

        if ($new_data) {
            unset($new_data["_method"]);
        }

        $result_array = self::customArrayDiff($new_data, $old_data)
            ?: self::modelArrayDiff($event_type);

        try {
            return AdminAuditLog::create([
                'user_id' => $user->id,
                'event_type' => $event_type,
                'event_description' => $description,
                'section' => AdminAudit::getSectionAttribute(),
                'main_table' => AdminAudit::getTableAttribute(),
                'ref_id' => intval($ref_id) ?: null,
                'changeset_json' => $result_array ? json_encode($result_array) : null,
            ]);
        } catch (QueryException $e) {
            Log::warning('Unable to log admin activity', [
                'code'=> $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * @param $event_type
     * @return array
     */
    private static function modelArrayDiff($event_type)
    {
        $diff = [];

        switch ($event_type) {
            case 'update':
            case 'delete':
            case 'create':
                if (AdminAudit::$l_updated || AdminAudit::$l_creating || AdminAudit::$l_deleting) {
                    $diff = self::createDiffArray($event_type);
                }
                break;
        }

        return $diff;
    }

    /**
     * @param array $new_data_array
     * @param array $old_data_array
     * @return array
     */
    private static function customArrayDiff(array $new_data_array, array $old_data_array) {
        $diff = [];
        foreach($new_data_array as $new_k => $new_v) {
            if (in_array($new_k, self::skipFields)) continue;
            // field exists in changed data
            if (isset($old_data_array[$new_k])) {
                // field is different in new data than old data, NOTE, add a check for subarray here
                if ($old_data_array[$new_k] != $new_data_array[$new_k]) {
                    $diff[$new_k] = [
                        "old_value" => self::replaceIfSensitiveData($new_k, $old_data_array[$new_k]),
                        "new_value" => self::replaceIfSensitiveData($new_k, $new_data_array[$new_k])
                    ];

                }
            } else {
                $diff[$new_k] = [
                    "old_value" => null,
                    "new_value" => self::replaceIfSensitiveData($new_k, $new_data_array[$new_k])
                ];
            }
        }

        // check if old fields exist in new array (shouldn't happen though)
        foreach($old_data_array as $old_k => $old_v) {
            if (in_array($old_k, self::skipFields)) continue;

            if (!isset($new_data_array[$old_k])) {
                $diff[$old_k] = [
                    "old_value" => self::replaceIfSensitiveData($old_k, $old_data_array[$old_k]),
                    "new_value" => null
                ];
            }
        }

        return $diff;
    }

    /**
     * @param $key
     * @param $value
     * @return string
     */
    private static function replaceIfSensitiveData($key, $value)
    {
        if (in_array($key, self::sensitiveData) && gettype($value) === 'string') {
            $splitString = str_split($value);
            $replacedStringArray = array_map(function () {
                return '*';
            }, $splitString);

            return implode('', $replacedStringArray);
        }

        return $value;
    }

    /**
     * @param string $event_type
     * @return array $diff
     */
    private static function createDiffArray($event_type = '')
    {
        $diff = [];

        if (in_array($event_type, ['create', 'update'])) {
            foreach (AdminAudit::$updatedData as $attr => $value) {

                if (in_array($attr, self::skipFields)) {
                    continue;
                }

                $oldValue = AdminAudit::$originalData[$attr] ?? null;

                $diff[$attr] = [
                    'old_value' => self::replaceIfSensitiveData($attr, $oldValue),
                    'new_value' => self::replaceIfSensitiveData($attr, $value)
                ];
            }
        } else {
            foreach (AdminAudit::$originalData as $attr => $value) {

                if (in_array($attr, self::skipFields)) {
                    continue;
                }

                $diff[$attr] = [
                    'old_value' => self::replaceIfSensitiveData($attr, $value),
                    'new_value' => null
                ];
            }
        }

        return $diff;
    }

    /**
     * @param $table
     * @param $name
     * @param array $changeset
     */
    public static function saveRelated($table, $name, $changeset = [])
    {
        $old_data = $changeset['old'] ?? collect([]);
        $new_data = $changeset['new'] ?? collect([]);

        if ($old_data instanceof coreModel || $new_data instanceof coreModel) {
            $new_data = $new_data->toArray();
            $old_data = $old_data->toArray();
            $result_array = self::relatedModelsDiff($new_data, $old_data);
        } else {
            $result_array = self::relatedCollectionsDiff($new_data, $old_data);
        }

        $insert = [];
        foreach ($result_array as $event_type => $changes) {
            switch ($event_type) {
                case 'update':
                    foreach($changes['old'] as $key => $val) {
                        $id = $val['id'] ?? null;
                        $newVal = !empty($changes['new'][$key]) ? $changes['new'][$key] : null;

                        if (!empty($newVal['id'])) {
                            unset($newVal['id']);
                        }

                        if (!empty($val['id'])) {
                            unset($val['id']);
                        }

                        $insert[] = [
                            'parent_log_id' => AdminAudit::$parentLogId,
                            'ref_id' => $id,
                            'main_table' => $table,
                            'name' => $name,
                            'event_type' => $event_type,
                            'new_value' => $newVal ? json_encode($newVal) : null,
                            'old_value' => $val ? json_encode($val) : null,
                        ];
                    }
                    break;
                case 'create':
                    foreach($changes['new'] as $key => $val) {
                        $id = $val['id'] ?? null;

                        if (!empty($val['id'])) {
                            unset($val['id']);
                        }

                        $insert[] = [
                            'parent_log_id' => AdminAudit::$parentLogId,
                            'ref_id' => $id,
                            'main_table' => $table,
                            'name' => $name,
                            'event_type' => $event_type,
                            'new_value' => $val ? json_encode($val) : null,
                            'old_value' => null,
                        ];
                    }
                    break;
                case 'delete':
                    foreach($changes['old'] as $key => $val) {
                        $id = $val['id'] ?? null;

                        if (!empty($val['id'])) {
                            unset($val['id']);
                        }

                        $insert[] = [
                            'parent_log_id' => AdminAudit::$parentLogId,
                            'ref_id' => $id,
                            'main_table' => $table,
                            'name' => $name,
                            'event_type' => $event_type,
                            'new_value' => null,
                            'old_value' => $val ? json_encode($val) : null
                        ];
                    }
                    break;
            }
        }

        if ($insert) {
            try {
                AdminAuditRelatedLog::insert($insert);
            } catch (QueryException $e) {
                Log::warning('Unable to log admin related activity', [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * @param $new_data
     * @param $old_data
     * @return array
     */
    private static function relatedCollectionsDiff($new_data, $old_data)
    {
        $old_data = $old_data->filter();
        $new_data = $new_data->filter();
        /** Get added records */
        $new = $new_data->diff($old_data);
        /** Get deleted records */
        $trashed = $old_data->diff($new_data);

        /** Get original records (have been just modified) */
        $original = collect([]);
        foreach ($old_data as $item) {
            $existsInNew = $new_data->search($item);
            $existsInTrashed = $trashed->search($item);
            if ($existsInNew === false && $existsInTrashed === false) {
                $original->push($item);
            }
        }

        /** Get updated values of modified records */
        $updated = $original->map(function ($item) use ($new_data) {
            return $new_data->where('id', $item->id)->first();
        });

        $created = $new ? $new->toArray() : [];
        $deleted = $trashed ? $trashed->toArray() : [];
        $updated = $updated ? $updated->toArray() : [];
        $original = $original ? $original->toArray() : [];

        return [
            'create' => [
                'old' => [],
                'new' => self::filterOutputArray($created)
            ],
            'update' => [
                'old' => self::filterOutputArray($original),
                'new' => self::filterOutputArray($updated)
            ],
            'delete' => [
                'old' => self::filterOutputArray($deleted),
                'new' => []
            ],
        ];
    }

    /**
     * @param array $data
     * @return array
     */
    private static function filterOutputArray(array $data)
    {
        $result = [];

        foreach ($data as $item) {
            $result[] = self::hideSensitiveOrIgnoreSkipped($item);
        }

        return $result;
    }

    /**
     * @param array $array
     * @return array
     */
    private static function hideSensitiveOrIgnoreSkipped(array $array)
    {
        foreach ($array as $key => $val) {
            if (is_array($val)) {
                self::hideSensitiveOrIgnoreSkipped($val);
            }

            if (in_array($key, self::skipFields) && $key !== 'id') {
                unset ($array[$key]);
            } else {
                $array[$key] = self::replaceIfSensitiveData($key, $val);
            }
        }

        return $array;
    }

    /**
     * @param array $new_data
     * @param array $old_data
     * @return array
     */
    private static function relatedModelsDiff(array $new_data, array $old_data)
    {
        $diff = [];

        foreach($new_data as $new_k => $new_v) {
            if (in_array($new_k, self::skipFields) && $new_k !== 'id') continue;

            if (array_key_exists($new_k, $old_data)) {
                if ($old_data[$new_k] != $new_v) {
                    $diff['update']['old'][0]['id'] = $new_data['id'] ?? null;
                    $diff['update']['old'][0][$new_k] = self::replaceIfSensitiveData($new_k, $old_data[$new_k]);
                    $diff['update']['new'][0]['id'] = $new_data['id'] ?? null;
                    $diff['update']['new'][0][$new_k] = self::replaceIfSensitiveData($new_k, $new_v);
                } else if ($new_v && !$old_data[$new_k]) {
                    $diff['create']['old'][0]['id'] = $new_data['id'] ?? null;
                    $diff['create']['old'][0][$new_k] = null;
                    $diff['create']['new'][0]['id'] = $new_data['id'] ?? null;
                    $diff['create']['new'][0][$new_k] = self::replaceIfSensitiveData($new_k, $new_v);
                } else if (!$new_v && $old_data[$new_k]) {
                    $diff['delete']['old'][0]['id'] = $new_data['id'] ?? null;
                    $diff['delete']['old'][0][$new_k] = self::replaceIfSensitiveData($new_k, $new_v);
                    $diff['delete']['new'][0]['id'] = $new_data['id'] ?? null;
                    $diff['delete']['new'][0][$new_k] = null;
                }
            } else {
                $diff['create']['old'][0]['id'] = $new_data['id'] ?? null;
                $diff['create']['old'][0][$new_k] = null;
                $diff['create']['new'][0][$new_k] = self::replaceIfSensitiveData($new_k, $new_v);
            }
        }

        foreach ($old_data as $old_k => $old_v) {
            if (in_array($old_k, self::skipFields) && $old_k !== 'id') continue;

            if (!array_key_exists($old_k, $new_data)) {
                $diff['delete']['old'][0]['id'] = $old_data['id'] ?? null;
                $diff['delete']['old'][0][$old_k] = self::replaceIfSensitiveData($old_k, $old_v);
                $diff['delete']['new'][0]['id'] = $old_data['id'] ?? null;
                $diff['delete']['new'][0][$old_k] = null;
            }
        }

        return $diff;
    }
}