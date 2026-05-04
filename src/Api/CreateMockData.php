<?php

namespace Sunnysideup\MockMe\Api;

use ReflectionClass;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBBigInt;
use SilverStripe\ORM\FieldType\DBBoolean;
use SilverStripe\ORM\FieldType\DBCurrency;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBDecimal;
use SilverStripe\ORM\FieldType\DBDouble;
use SilverStripe\ORM\FieldType\DBEmail;
use SilverStripe\ORM\FieldType\DBFloat;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\FieldType\DBHTMLVarchar;
use SilverStripe\ORM\FieldType\DBInt;
use SilverStripe\ORM\FieldType\DBPercentage;
use SilverStripe\ORM\FieldType\DBText;
use SilverStripe\ORM\FieldType\DBTime;
use SilverStripe\ORM\FieldType\DBVarchar;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Control\Director;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\ORM\FieldType\DBYear;
use SilverStripe\Security\Member;
use SilverStripe\Security\MemberPassword;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use Throwable;

/**
 * Walks every DataObject in the install and seeds mock data.
 *
 * Phase 1 — base records
 *   For each non-excluded, non-abstract DataObject subclass, create
 *   ::$records_per_class rows with values intelligently guessed from
 *   the field NAME first (Email, FirstName, Address, Postcode, Price, …)
 *   and falling back to the field TYPE (Varchar, Int, HTMLText, Enum, …).
 *
 * Phase 2 — relationships
 *   Walk the same classes again and wire up has_one / has_many / many_many
 *   using the IDs created in phase 1.
 *
 * Usage:
 *   \Sunnysideup\MockMe\Api\CreateMockData::create()->run();
 *
 * Configuration (in YML):
 *   Sunnysideup\MockMe\Api\CreateMockData:
 *     records_per_class: 12
 *     skip_validation: true
 *     auto_fix_validation_errors: true
 *     truncate_before_create: false  # WARNING: Deletes ALL data!
 *     classes_to_exclude:
 *       - 'MyApp\\Models\\SensitiveData'
 *     fields_to_skip_per_class:
 *       'MyApp\\Models\\MyClass':
 *         - 'ProblematicField'
 *         - 'AnotherField'
 *     field_values_per_class:
 *       'MyApp\\Models\\MyClass':
 *         HideTitle: false
 *         Status: 'Active'
 *
 * Command-line usage:
 *   vendor/bin/sake dev/tasks/CreateMockDataRunner
 *   vendor/bin/sake dev/tasks/CreateMockDataRunner --reset  # Truncates all tables first!
 *
 * Typically you'd call it from a BuildTask. Don't run it on a live site.
 */
class CreateMockData
{
    use Injectable;
    use Extensible;
    use Configurable;

    /**
     * How many rows to create per concrete DataObject class.
     */
    private static int $records_per_class = 12;

    /**
     * How many related rows to attach per record for has_many / many_many.
     */
    private static int $relations_per_record = 2;

    /**
     * Classes that should never be touched.
     */
    private static array $classes_to_exclude = [
        MemberPassword::class,
        Permission::class,
        'SilverStripe\\HybridSessions\\HybridSessionDataObject',
        'SilverStripe\\Assets\\File',
        'SilverStripe\\Assets\\Image',
        'SilverStripe\\Assets\\Folder',
        'SilverStripe\\Versioned\\ChangeSet',
        'SilverStripe\\Versioned\\ChangeSetItem',
        'SilverStripe\\Assets\\Shortcodes\\FileLink',
        'SilverStripe\\CMS\\Model\\SiteTreeLink',
        'SilverStripe\\Security\\Group',
        'SilverStripe\\Security\\LoginAttempt',
        'SilverStripe\\Security\\Member',
        'SilverStripe\\Security\\PermissionRole',
        'SilverStripe\\Security\\PermissionRoleCode',
        'SilverStripe\\Security\\RememberLoginHash',
        'SilverStripe\\MFA\\Model\\RegisteredMethod',
        'SilverStripe\\SessionManager\\Models\\LoginSession',
        'Sunnysideup\\FlushFrontEnd\\Model\\FlushRecord',
        'SilverStripe\\Reports\\ExternalLinks\\Model\\BrokenExternalLink',
        'SilverStripe\\Reports\\ExternalLinks\\Model\\BrokenExternalPageTrack',
        'SilverStripe\\Reports\\ExternalLinks\\Model\\BrokenExternalPageTrackStatus',
        'SilverStripe\\ErrorPage\\ErrorPage',
        'SilverStripe\\UserForms\\Model\\EditableFormField',
        'SilverStripe\\SiteConfig\\SiteConfig',
    ];

    /**
     * Fields that should never be written to directly (set by the ORM).
     */
    private static array $fields_to_skip = [
        'ID', 'ClassName', 'LastEdited', 'Created', 'Version',
    ];

    /**
     * Skip validation when writing records.
     * Set to true to bypass validation errors.
     */
    private static bool $skip_validation = true;

    /**
     * Enable detailed validation error reporting.
     * When true, shows all validation errors and attempted fixes.
     */
    private static bool $report_validation_errors = true;

    /**
     * Try to automatically fix validation errors.
     * When true, attempts to fix common validation issues before failing.
     */
    private static bool $auto_fix_validation_errors = true;

    /**
     * Skip specific field/class combinations.
     * Format: ['ClassName' => ['FieldName1', 'FieldName2']]
     * Example: ['MyApp\\Models\\MyClass' => ['ProblematicField', 'AnotherField']]
     */
    private static array $fields_to_skip_per_class = [];

    /**
     * Set specific values for field/class combinations.
     * Format: ['ClassName' => ['FieldName' => 'value']]
     * Example: ['MyApp\\Models\\MyClass' => ['Status' => 'Active', 'Enabled' => true]]
     */
    private static array $field_values_per_class = [];

    /**
     * Additional classes to skip (in addition to classes_to_exclude).
     * Useful for temporary exclusions or project-specific skips.
     * Format: ['ClassName1', 'ClassName2']
     * Example: ['MyApp\\Models\\ProblematicClass', 'MyApp\\Models\\AnotherClass']
     * This is NOT recursive — it only skips the specified classes, not their subclasses.
     * Use classes_to_exclude for recursive exclusion.
     */
    private static array $classes_to_skip = [
        'DNADesign\\Elemental\\Models\\BaseElement',
        'SilverStripe\\CMS\\Model\\SiteTree',
    ];

    /**
     * Classes to force create even if canCreate() returns false.
     * Use with caution - this bypasses permission checks.
     * Format: ['ClassName1', 'ClassName2']
     * Example: ['MyApp\\Models\\SpecialClass']
     */
    private static array $force_create_classes = [];

    /**
     * Truncate all tables before generating mock data.
     * WARNING: This will delete ALL data from the database!
     * Only use this in development environments.
     */
    private static bool $truncate_before_create = false;

    /**
     * ClassName => array of IDs we created in phase 1.
     * Used as the candidate pool when wiring relationships.
     *
     * @var array<string, int[]>
     */
    protected array $createdIds = [];

    /**
     * Track created file IDs to reuse them.
     *
     * @var array<string, int[]>
     */
    protected array $createdFileIds = [];

    /**
     * Track errors by class for summary reporting.
     *
     * @var array<string, int>
     */
    protected array $errorCount = [];

    /**
     * Track validation errors that couldn't be auto-fixed.
     * Format: ['ClassName.FieldName' => ['error' => 'message', 'count' => int]]
     *
     * @var array
     */
    protected array $unfixableErrors = [];

    // =================================================================
    //  Entry point
    // =================================================================

    /**
     * Truncate all database tables.
     * WARNING: This deletes ALL data!
     */
    protected function truncateAllTables(): void
    {
        DB::alteration_message('=== TRUNCATING ALL TABLES ===', 'error');
        DB::alteration_message('WARNING: This will delete ALL data from the database!', 'error');


        // Get all tables
        $tables = DB::table_list();

        // Tables to never truncate (system/migration tables)
        $skipTables = [
        ];

        $truncatedCount = 0;

        try {
            // Disable foreign key checks temporarily
            DB::query('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($tables as $table) {
                // Skip system tables and migration tables
                if (in_array($table, $skipTables)) {
                    continue;
                }

                // Skip tables that start with underscore (internal)
                if (strpos($table, '_') === 0) {
                    continue;
                }

                try {
                    DB::query("TRUNCATE TABLE `{$table}`");
                    $truncatedCount++;
                } catch (Throwable $e) {
                    DB::alteration_message("  ! Failed to truncate {$table}: {$e->getMessage()}", 'error');
                }
            }

            // Re-enable foreign key checks
            DB::query('SET FOREIGN_KEY_CHECKS = 1');

            DB::alteration_message("Truncated {$truncatedCount} tables", 'created');

        } catch (Throwable $e) {
            // Make sure we re-enable foreign keys even if something fails
            DB::query('SET FOREIGN_KEY_CHECKS = 1');
            DB::alteration_message("Error during truncation: {$e->getMessage()}", 'error');
            throw $e;
        }
    }

    public function run(): void
    {
        // Check if truncate is requested
        $truncate = (bool) Config::inst()->get(static::class, 'truncate_before_create');

        // Safety check: never truncate on live
        if ($truncate && Director::isLive()) {
            DB::alteration_message('DANGER: Cannot truncate database on LIVE environment!', 'error');
            return;
        }

        // Login as admin to bypass permission checks
        $admin = Permission::get_members_by_permission('ADMIN')->first();
        if (!$admin) {
            DB::alteration_message('No admin user found. Please create an admin user first.', 'error');
            return;
        }
        Security::setCurrentUser($admin);
        DB::alteration_message("Logged in as admin: {$admin->Email}", 'notice');

        // Truncate tables if requested
        if ($truncate) {
            $this->truncateAllTables();
        }

        $skipValidation = (bool) Config::inst()->get(static::class, 'skip_validation');
        $autoFix = (bool) Config::inst()->get(static::class, 'auto_fix_validation_errors');

        if ($skipValidation) {
            DB::alteration_message("Validation: DISABLED (records may not be fully valid)", 'notice');
        } else {
            DB::alteration_message("Validation: ENABLED", 'notice');
        }

        if ($autoFix) {
            DB::alteration_message("Auto-fix validation errors: ENABLED", 'notice');
        } else {
            DB::alteration_message("Auto-fix validation errors: DISABLED", 'notice');
        }

        DB::alteration_message("Circular reference prevention: ENABLED", 'notice');

        $classes = $this->getEligibleClasses();

        DB::alteration_message('=== Phase 1: creating base records ===', 'created');
        foreach ($classes as $className) {
            $this->createRecordsForClass($className);
        }

        DB::alteration_message('=== Phase 2: wiring relationships ===', 'created');
        foreach ($classes as $className) {
            $this->wireRelationshipsForClass($className);
        }

        DB::alteration_message('=== Summary ===', 'created');

        // Count successes
        $totalCreated = 0;
        foreach ($this->createdIds as $ids) {
            $totalCreated += count($ids);
        }

        // Count errors
        $totalErrors = array_sum($this->errorCount);

        DB::alteration_message("Created {$totalCreated} records successfully", 'created');

        if ($totalErrors > 0) {
            DB::alteration_message("Failed to create {$totalErrors} records", 'error');
            DB::alteration_message("Classes with most errors:", 'notice');

            // Sort by error count descending
            arsort($this->errorCount);
            $top = array_slice($this->errorCount, 0, 10, true);

            foreach ($top as $class => $count) {
                $shortClass = substr($class, strrpos($class, '\\') + 1);
                DB::alteration_message("  - {$shortClass}: {$count} errors", 'notice');
            }
        }

        // Show unfixable validation errors with solutions
        if (!empty($this->unfixableErrors)) {
            DB::alteration_message('=== Validation Errors That Could Not Be Auto-Fixed ===', 'error');
            DB::alteration_message('Add these to your YML config to skip or set specific values:', 'notice');
            DB::alteration_message('', 'notice');

            // Group by class
            $byClass = [];
            foreach ($this->unfixableErrors as $key => $data) {
                list($className, $fieldName) = explode('.', $key, 2);
                if (!isset($byClass[$className])) {
                    $byClass[$className] = [];
                }
                $byClass[$className][$fieldName] = $data;
            }

            foreach ($byClass as $className => $fields) {
                DB::alteration_message("  {$className}:", 'notice');
                DB::alteration_message("    fields_to_skip_per_class:", 'notice');
                DB::alteration_message("      '{$className}':", 'notice');
                foreach ($fields as $fieldName => $data) {
                    $count = $data['count'];
                    $error = $data['error'];
                    $fieldType = $data['fieldType'];
                    DB::alteration_message("        - '{$fieldName}'  # {$error} ({$fieldType}) - failed {$count}x", 'notice');
                }
                DB::alteration_message('', 'notice');
            }
        }

        DB::alteration_message('Mock data generation complete.', 'created');
    }

    // =================================================================
    //  Class discovery
    // =================================================================

    protected function getEligibleClasses(): array
    {
        $exclude = (array) Config::inst()->get(static::class, 'classes_to_exclude');
        $skips = (array) Config::inst()->get(static::class, 'classes_to_skip');

        $all = ClassInfo::subclassesFor(DataObject::class, false);
        $out = [];
        foreach ($all as $className) {
            // Check exact match or if it's a subclass of excluded classes
            foreach ($exclude as $excludedClass) {
                if ($className === $excludedClass || is_subclass_of($className, $excludedClass)) {
                    continue 2; // Skip to next className
                }
            }
            foreach ($skips as $skippedClass) {
                if ($className === $skippedClass) {
                    continue 2; // Skip to next className
                }
            }

            try {
                $reflection = new ReflectionClass($className);
            } catch (Throwable $e) {
                DB::alteration_message("Skipping {$className}: {$e->getMessage()}", 'error');
                continue;
            }
            if ($reflection->isAbstract()) {
                continue;
            }
            $out[] = $className;
        }
        return $out;
    }

    // =================================================================
    //  Phase 1 — base records
    // =================================================================

    protected function createRecordsForClass(string $className): void
    {
        $count  = (int) Config::inst()->get(static::class, 'records_per_class');
        $skip   = (array) Config::inst()->get(static::class, 'fields_to_skip');
        $config = Injector::inst()->get($className)->config();
        $dbFields = (array) $config->get('db');
        $defaults = (array) $config->get('defaults');

        // Get class-specific field configurations
        $fieldsToSkipPerClass = (array) Config::inst()->get(static::class, 'fields_to_skip_per_class');
        $fieldValuesPerClass = (array) Config::inst()->get(static::class, 'field_values_per_class');

        $classFieldsToSkip = $fieldsToSkipPerClass[$className] ?? [];
        $classFieldValues = $fieldValuesPerClass[$className] ?? [];

        // Check if we can create records for this class
        $forceCreate = (array) Config::inst()->get(static::class, 'force_create_classes');
        $shouldForceCreate = in_array($className, $forceCreate, true);

        if (!$shouldForceCreate) {
            /** @var DataObject $testObj */
            $testObj = Injector::inst()->create($className);
            if (!$testObj->canCreate()) {
                $shortClass = substr($className, strrpos($className, '\\') + 1);
                DB::alteration_message("  - Skipping {$shortClass} (canCreate=false)", 'notice');
                return;
            }
        }

        $created = 0;
        for ($i = 1; $i <= $count; $i++) {
            $lastField = null;
            $lastValue = null;
            $lastType = null;
            try {
                /** @var DataObject $obj */
                $obj = Injector::inst()->create($className);

                foreach ($dbFields as $fieldName => $fieldType) {
                    // Skip globally excluded fields
                    if (in_array($fieldName, $skip, true)) {
                        continue;
                    }

                    // Skip class-specific excluded fields
                    if (in_array($fieldName, $classFieldsToSkip, true)) {
                        continue;
                    }

                    $lastField = $fieldName;
                    $lastType = $fieldType;

                    // Priority 1: Class-specific field value
                    if (isset($classFieldValues[$fieldName])) {
                        $value = $classFieldValues[$fieldName];
                    }
                    // Priority 2: Default value from DataObject
                    elseif (isset($defaults[$fieldName])) {
                        $value = $defaults[$fieldName];
                    }
                    // Priority 3: Generate value
                    else {
                        $value = $this->generateValueForField($fieldName, (string) $fieldType, $i);
                    }

                    $lastValue = $value;
                    if ($value !== null) {
                        $obj->$fieldName = $value;
                    }
                }

                $skipValidation = (bool) Config::inst()->get(static::class, 'skip_validation');

                // Check if auto-fixing is enabled
                $autoFix = (bool) Config::inst()->get(static::class, 'auto_fix_validation_errors');

                // Try to validate and auto-fix common issues
                $attempts = 0;
                $maxAttempts = 3;
                $validationErrors = [];

                if ($autoFix) {
                    while ($attempts < $maxAttempts) {
                        $validationResult = $obj->validate();

                        if ($validationResult->isValid()) {
                            break; // Success!
                        }

                        $validationErrors = $validationResult->getMessages();
                        $fixed = false;

                        // Try to auto-fix validation errors
                        foreach ($validationErrors as $error) {
                            $fieldName = $error['fieldName'] ?? null;
                            $messageText = $error['message'] ?? '';

                            if ($fieldName) {
                                $wasFixed = $this->tryFixValidationError($obj, $fieldName, $messageText, $dbFields, $className);
                                if ($wasFixed) {
                                    $fixed = true;
                                } else {
                                    // Track unfixable errors
                                    $key = "{$className}.{$fieldName}";
                                    if (!isset($this->unfixableErrors[$key])) {
                                        $this->unfixableErrors[$key] = [
                                            'error' => $messageText,
                                            'count' => 0,
                                            'fieldType' => $dbFields[$fieldName] ?? 'unknown',
                                        ];
                                    }
                                    $this->unfixableErrors[$key]['count']++;
                                }
                            }
                        }

                        if (!$fixed) {
                            // Can't auto-fix, break out
                            break;
                        }

                        $attempts++;
                    }
                } else {
                    // Just validate without fixing
                    $validationResult = $obj->validate();
                    if (!$validationResult->isValid()) {
                        $validationErrors = $validationResult->getMessages();
                    }
                }

                // If validation still fails and we're not skipping, throw error
                if (!$skipValidation && !$validationResult->isValid()) {
                    $errors = [];
                    foreach ($validationErrors as $message) {
                        $fieldName = $message['fieldName'] ?? 'unknown';
                        $messageText = $message['message'] ?? 'unknown error';
                        $errors[] = "{$fieldName} → {$messageText}";
                    }
                    throw new \Exception(implode(', ', $errors));
                }

                // write($showDebug, $forceInsert, $forceWrite, $writeComponents, $skipValidation)
                $id = (int) $this->writeObject($obj, $className, true);
                $this->createdIds[$className][] = $id;
                $created++;
            } catch (Throwable $e) {
                // Format error message more clearly
                $errorMsg = "{$className} #{$i} FAILED";

                // Add the main error message
                $mainError = $e->getMessage();
                // Clean up common error prefixes
                $mainError = str_replace('Invalid value - fieldName: ', '', $mainError);
                $mainError = str_replace(', recordID: 0, dataClass: ' . $className, '', $mainError);
                $errorMsg .= " → {$mainError}";

                // Add field context if available
                if ($lastField !== null) {
                    $valueStr = is_scalar($lastValue) ? var_export($lastValue, true) : gettype($lastValue);
                    $errorMsg .= " | Last: {$lastField}={$valueStr}";
                }

                DB::alteration_message($errorMsg, 'error');

                // Track error count
                if (!isset($this->errorCount[$className])) {
                    $this->errorCount[$className] = 0;
                }
                $this->errorCount[$className]++;
            }
        }

        $shortClass = substr($className, strrpos($className, '\\') + 1);
        if ($created > 0) {
            DB::alteration_message("  + {$created} × {$shortClass}", 'created');
        } else {
            DB::alteration_message("  - 0 × {$shortClass} (all {$count} attempts failed)", 'error');
        }
    }

    // =================================================================
    //  Phase 2 — relationships
    // =================================================================

    protected function wireRelationshipsForClass(string $className): void
    {
        if (empty($this->createdIds[$className])) {
            return;
        }
        $perRecord = (int) Config::inst()->get(static::class, 'relations_per_record');
        $config    = Injector::inst()->get($className)->config();
        $hasOne    = (array) $config->get('has_one');
        $hasMany   = (array) $config->get('has_many');
        $manyMany  = (array) $config->get('many_many');

        foreach ($this->createdIds[$className] as $id) {
            /** @var DataObject|null $obj */
            $obj = $className::get()->byID($id);
            if (!$obj) {
                continue;
            }

            // --- has_one: one random existing record of the related class
            $changed = false;
            foreach ($hasOne as $relationName => $relatedClass) {
                $relatedClass = $this->normaliseRelatedClass($relatedClass);
                if (!$relatedClass) {
                    continue;
                }

                // Special handling for File/Image has_one relationships
                if (is_a($relatedClass, File::class, true)) {
                    $fileId = $this->getOrCreateDummyFile($relatedClass);
                    if ($fileId) {
                        $field = $relationName . 'ID';
                        $obj->$field = $fileId;
                        $changed = true;
                    }
                    continue;
                }

                $candidates = $this->candidateIdsFor($relatedClass);
                if (!$candidates) {
                    continue;
                }

                // Prevent circular references
                $field = $relationName . 'ID';
                $safeCandidate = $this->getSafeCandidateId($obj, $field, $relatedClass, $candidates, $relationName);
                if ($safeCandidate) {
                    $obj->$field = $safeCandidate;
                    $changed = true;
                }
            }

            if ($changed) {
                try {
                    $skipValidation = (bool) Config::inst()->get(static::class, 'skip_validation');
                    $this->writeObject($obj, $className, $skipValidation);
                } catch (Throwable $e) {
                    $shortClass = substr($className, strrpos($className, '\\') + 1);
                    $errorMsg = $e->getMessage();
                    // Simplify error message
                    $errorMsg = str_replace('Invalid value - fieldName: ', '', $errorMsg);
                    DB::alteration_message(
                        "Relationship error: {$shortClass}#{$id} → {$errorMsg}",
                        'error'
                    );
                    continue;
                }
            }

            // --- has_many and many_many: attach a couple of related records
            foreach ($hasMany as $relationName => $relatedClass) {
                $this->attachRelations($obj, $relationName, $relatedClass, $perRecord);
            }
            foreach ($manyMany as $relationName => $relatedClass) {
                $this->attachRelations($obj, $relationName, $relatedClass, $perRecord);
            }
        }
    }

    protected function attachRelations(
        DataObject $obj,
        string $relationName,
        $relatedClass,
        int $perRecord
    ): void {
        $relatedClass = $this->normaliseRelatedClass($relatedClass);
        if (!$relatedClass) {
            return;
        }
        if (!$obj->hasMethod($relationName)) {
            return;
        }
        $candidates = $this->candidateIdsFor($relatedClass);
        if (!$candidates) {
            return;
        }

        // Remove self from candidates to prevent self-relations
        $candidates = array_diff($candidates, [$obj->ID]);
        if (!$candidates) {
            return;
        }

        try {
            $relation = $obj->$relationName();
        } catch (Throwable $e) {
            return;
        }

        $n = min($perRecord, count($candidates));
        $keys = $n === 1 ? [array_rand($candidates)] : (array) array_rand($candidates, $n);

        foreach ($keys as $key) {
            $relatedId = (int) $candidates[$key];

            // Skip if this would create a circular reference
            if ($relatedId === $obj->ID) {
                continue;
            }

            $other = $relatedClass::get()->byID($relatedId);
            if (!$other) {
                continue;
            }
            try {
                $relation->add($other);
            } catch (Throwable $e) {
                // silently skip — some relations have constraints we can't satisfy
            }
        }
    }

    protected function normaliseRelatedClass($related): ?string
    {
        $class = null;
        if (is_string($related) && class_exists($related)) {
            $class = $related;
        } elseif (is_array($related) && !empty($related['class']) && class_exists($related['class'])) {
            // SS4+ polymorphic has_one: ['SomeRel' => ['class' => Foo::class, ...]]
            $class = $related['class'];
        }
        // skip pure polymorphic — we don't know what concrete class to point at
        if ($class === DataObject::class) {
            return null;
        }
        return $class;
    }

    protected function candidateIdsFor(string $class): array
    {
        if (!empty($this->createdIds[$class])) {
            return $this->createdIds[$class];
        }
        // class wasn't in our run (e.g. excluded, or pre-existing like Member).
        // Fall back to whatever's already in the DB.
        try {
            $list = DataObject::get($class)->limit(20)->column('ID');
            return $list ? array_map('intval', (array) $list) : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Get a safe candidate ID that won't create circular references.
     * Handles special cases like Parent/Child hierarchies and prevents self-references.
     */
    protected function getSafeCandidateId(
        DataObject $obj,
        string $field,
        string $relatedClass,
        array $candidates,
        string $relationName
    ): ?int {
        // Remove self-reference
        $candidates = array_diff($candidates, [$obj->ID]);

        if (empty($candidates)) {
            return null;
        }

        // Special handling for hierarchical relationships (Parent, etc.)
        if ($this->isHierarchicalRelation($relationName, $relatedClass)) {
            // For hierarchical relations, only point to objects that were created before this one
            // or objects not in our created set (pre-existing records)
            $safeCandidates = [];
            foreach ($candidates as $candidateId) {
                // If it's in our created IDs for this class, check if it has a lower ID
                if (isset($this->createdIds[$relatedClass]) && in_array($candidateId, $this->createdIds[$relatedClass])) {
                    if ($candidateId < $obj->ID) {
                        $safeCandidates[] = $candidateId;
                    }
                } else {
                    // Pre-existing record, safe to use
                    $safeCandidates[] = $candidateId;
                }
            }
            $candidates = $safeCandidates;
        }

        // Check if the same class to avoid simple circular references
        if ($relatedClass === get_class($obj)) {
            // For same-class relations, avoid pointing to objects that point back to us
            $safeCandidates = [];
            foreach ($candidates as $candidateId) {
                try {
                    $candidate = $relatedClass::get()->byID($candidateId);
                    if ($candidate && $candidate->hasField($field)) {
                        // Check if this candidate already points to us
                        if ($candidate->$field != $obj->ID) {
                            $safeCandidates[] = $candidateId;
                        }
                    } else {
                        $safeCandidates[] = $candidateId;
                    }
                } catch (Throwable $e) {
                    // If we can't check, skip this candidate
                    continue;
                }
            }
            $candidates = $safeCandidates;
        }

        if (empty($candidates)) {
            return null;
        }

        return $candidates[array_rand($candidates)];
    }

    /**
     * Check if a relation is hierarchical (Parent/Child style).
     */
    protected function isHierarchicalRelation(string $relationName, string $relatedClass): bool
    {
        $hierarchicalNames = ['Parent', 'ParentID', 'ParentPage', 'ParentGroup'];

        if (in_array($relationName, $hierarchicalNames)) {
            return true;
        }

        // Check if it's a self-referential Parent relationship
        // (same class, relation name contains 'parent')
        if (stripos($relationName, 'parent') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Get or create a dummy file for testing purposes.
     * Creates real placeholder images/files that can be used in the application.
     */
    protected function getOrCreateDummyFile(string $className): ?int
    {
        // Check if we already have files for this class
        if (!empty($this->createdFileIds[$className])) {
            return $this->createdFileIds[$className][array_rand($this->createdFileIds[$className])];
        }

        // Create a few dummy files to choose from
        $count = 3;
        for ($i = 1; $i <= $count; $i++) {
            try {
                $isImage = is_a($className, Image::class, true);

                if ($isImage) {
                    // Create a simple placeholder image
                    $width = 800;
                    $height = 600;
                    $image = imagecreatetruecolor($width, $height);

                    // Random background color
                    $colors = [
                        [255, 0, 0],     // Red
                        [0, 0, 255],     // Blue
                        [0, 255, 0],     // Green
                        [255, 165, 0],   // Orange
                        [128, 0, 128],   // Purple
                    ];
                    $color = $colors[$i % count($colors)];
                    $bg = imagecolorallocate($image, $color[0], $color[1], $color[2]);
                    imagefill($image, 0, 0, $bg);

                    // Add text
                    $textColor = imagecolorallocate($image, 255, 255, 255);
                    $text = "Mock Image {$i}";
                    imagestring($image, 5, 340, 290, $text, $textColor);

                    // Save to temp file
                    $tmpFile = tempnam(sys_get_temp_dir(), 'mock_img_');
                    imagejpeg($image, $tmpFile, 90);
                    imagedestroy($image);

                    $filename = "mock-image-{$i}.jpg";
                } else {
                    // Create a simple text file
                    $tmpFile = tempnam(sys_get_temp_dir(), 'mock_file_');
                    file_put_contents($tmpFile, "Mock file content {$i}\nCreated for testing purposes.");
                    $filename = "mock-file-{$i}.txt";
                }

                // Create File/Image object and store it
                /** @var File $file */
                $file = Injector::inst()->create($className);
                $file->setFromLocalFile($tmpFile, $filename);
                $file->Title = "Mock " . ($isImage ? "Image" : "File") . " {$i}";
                $this->writeObject($file, $className, true);
                $file->publishSingle();

                $this->createdFileIds[$className][] = $file->ID;

                // Clean up temp file
                @unlink($tmpFile);

            } catch (Throwable $e) {
                $shortClass = substr($className, strrpos($className, '\\') + 1);
                $errorMsg = $e->getMessage();
                // Simplify error message
                if (strpos($errorMsg, 'Permission denied') !== false) {
                    $errorMsg = 'Permission denied - check assets folder permissions';
                } elseif (strpos($errorMsg, 'Unable to create') !== false) {
                    $errorMsg = 'Cannot create directory - check assets folder permissions';
                }
                DB::alteration_message(
                    "File creation error: {$shortClass} → {$errorMsg}",
                    'error'
                );
            }
        }

        if (!empty($this->createdFileIds[$className])) {
            DB::alteration_message("  + Created " . count($this->createdFileIds[$className]) . " dummy files for {$className}", 'created');
            return $this->createdFileIds[$className][array_rand($this->createdFileIds[$className])];
        }

        return null;
    }

    // =================================================================
    //  Field value generation
    // =================================================================

    protected function generateValueForField(string $name, string $type, int $index)
    {
        // 1. try to guess from the field name (most specific signal)
        $byName = $this->guessByName($name, $index);
        if ($byName !== null) {
            return $this->ensureMaxLength($byName, $type);
        }
        // 2. fall back to the SilverStripe field type
        $value = $this->guessByType($type, $name, $index);
        return $this->ensureMaxLength($value, $type);
    }

    /**
     * Try to automatically fix common validation errors.
     * Returns true if a fix was successfully applied, false otherwise.
     *
     * Universal fixes for common SilverStripe validation patterns:
     * - Required fields
     * - Enum value constraints
     * - Type mismatches (string/int/boolean)
     * - String length limits
     * - Date format issues
     */
    protected function tryFixValidationError(
        DataObject $obj,
        string $fieldName,
        string $errorMessage,
        array $dbFields,
        string $className
    ): bool {
        $errorLower = strtolower($errorMessage);
        $fieldType = $dbFields[$fieldName] ?? null;

        if (!$fieldType) {
            return false;
        }

        // Extract field type without parameters
        $baseType = preg_replace('/\(.*\)/', '', $fieldType);

        // Fix 1: Required field is empty
        if (str_contains($errorLower, 'required') || str_contains($errorLower, 'cannot be empty')) {
            $value = $this->generateValueForField($fieldName, $fieldType, 1);
            if ($value !== null) {
                $obj->$fieldName = $value;
                return true;
            }
        }

        // Fix 2: Boolean field validation issues - try opposite value
        if ($baseType === 'Boolean' || str_contains($fieldType, 'Boolean')) {
            $currentValue = $obj->$fieldName ?? 0;
            $obj->$fieldName = $currentValue ? 0 : 1;
            return true;
        }

        // Fix 3: Enum field - not an allowed value
        if (str_contains($errorLower, 'not an allowed value') ||
            (str_contains($errorLower, 'must be a string') && str_contains($fieldType, 'Enum'))) {
            if (preg_match("/Enum\s*\(\s*['\"]([^'\"]+)['\"]/", $fieldType, $m)) {
                $values = array_map('trim', explode(',', $m[1]));
                if (count($values) > 0) {
                    $obj->$fieldName = $values[0];
                    return true;
                }
            }
        }

        // Fix 4: Must be an integer
        if (str_contains($errorLower, 'must be an integer') ||
            str_contains($errorLower, 'must be a number') ||
            str_contains($errorLower, 'must be numeric')) {
            $obj->$fieldName = 1;
            return true;
        }

        // Fix 5: String too long
        if (str_contains($errorLower, 'too long') || str_contains($errorLower, 'exceeds')) {
            if (preg_match('/Varchar\((\d+)\)/i', $fieldType, $matches)) {
                $maxLength = (int) $matches[1];
                $currentValue = (string) ($obj->$fieldName ?? '');
                if (strlen($currentValue) > $maxLength) {
                    $obj->$fieldName = substr($currentValue, 0, $maxLength);
                    return true;
                }
            }
        }

        // Fix 6: Invalid date format
        if (str_contains($errorLower, 'invalid date') || str_contains($errorLower, 'date format')) {
            $obj->$fieldName = date('Y-m-d');
            return true;
        }

        // Fix 7: Try setting to null/empty for generic invalid value errors
        if (str_contains($errorLower, 'invalid value')) {
            $obj->$fieldName = null;
            return true;
        }

        // Could not auto-fix this error
        return false;
    }

    /**
     * Ensure the value doesn't exceed the maximum length for Varchar fields.
     */
    protected function ensureMaxLength($value, string $type)
    {
        // Only apply to string values
        if (!is_string($value)) {
            return $value;
        }

        // Extract length from Varchar(255), DBVarchar(100), HTMLVarchar(50), etc.
        if (preg_match('/Varchar\((\d+)\)/i', $type, $matches)) {
            $maxLength = (int) $matches[1];
            if (strlen($value) > $maxLength) {
                return substr($value, 0, $maxLength);
            }
        }

        return $value;
    }

    /**
     * Map of common field-name patterns to value generators.
     * Order matters: more specific keys should appear before more general ones
     * so the substring fallback at the bottom doesn't claim them prematurely.
     */
    protected function guessByName(string $name, int $i)
    {
        $n = strtolower($name);
        $p = $this->dataPools();

        $first = $p['firstNames'][$i % count($p['firstNames'])];
        $last  = $p['lastNames'][$i % count($p['lastNames'])];
        $co    = $p['companies'][$i % count($p['companies'])];

        $map = [
            // ---- people ---------------------------------------------------
            'email'         => fn () => sprintf('user%d.%s@example.com', $i, strtolower($first)),
            'emailaddress'  => fn () => sprintf('user%d.%s@example.com', $i, strtolower($first)),
            'firstname'     => fn () => $first,
            'lastname'      => fn () => $last,
            'surname'       => fn () => $last,
            'middlename'    => fn () => $p['firstNames'][($i + 3) % count($p['firstNames'])],
            'fullname'      => fn () => "{$first} {$last}",
            'username'      => fn () => strtolower($first) . $i,
            'nickname'      => fn () => $first,
            'salutation'    => fn () => ['Mr', 'Ms', 'Mrs', 'Dr', 'Mx'][$i % 5],
            'gender'        => fn () => ['Male', 'Female', 'Other'][$i % 3],
            'jobtitle'      => fn () => $p['jobTitles'][$i % count($p['jobTitles'])],
            'role'          => fn () => $p['jobTitles'][$i % count($p['jobTitles'])],
            'department'    => fn () => $p['departments'][$i % count($p['departments'])],
            'company'       => fn () => $co,
            'organisation'  => fn () => $co,
            'organization'  => fn () => $co,
            'companyname'   => fn () => $co,

            // ---- contact --------------------------------------------------
            'phone'         => fn () => $this->fakePhone($i),
            'phonenumber'   => fn () => $this->fakePhone($i),
            'mobile'        => fn () => $this->fakePhone($i),
            'mobilenumber'  => fn () => $this->fakePhone($i),
            'fax'           => fn () => $this->fakePhone($i),
            'telephone'     => fn () => $this->fakePhone($i),

            // ---- address --------------------------------------------------
            'address'       => fn () => sprintf('%d %s', 10 + $i, $p['streets'][$i % count($p['streets'])]),
            'address1'      => fn () => sprintf('%d %s', 10 + $i, $p['streets'][$i % count($p['streets'])]),
            'address2'      => fn () => 'Apt ' . $i,
            'streetaddress' => fn () => sprintf('%d %s', 10 + $i, $p['streets'][$i % count($p['streets'])]),
            'street'        => fn () => $p['streets'][$i % count($p['streets'])],
            'streetname'    => fn () => $p['streets'][$i % count($p['streets'])],
            'streetnumber'  => fn () => (string) (10 + $i),
            'suburb'        => fn () => $p['cities'][$i % count($p['cities'])],
            'city'          => fn () => $p['cities'][$i % count($p['cities'])],
            'town'          => fn () => $p['cities'][$i % count($p['cities'])],
            'state'         => fn () => $p['states'][$i % count($p['states'])],
            'region'        => fn () => $p['states'][$i % count($p['states'])],
            'province'      => fn () => $p['states'][$i % count($p['states'])],
            'country'       => fn () => $p['countries'][$i % count($p['countries'])],
            'countrycode'   => fn () => 'NZ',
            'postcode'      => fn () => sprintf('%04d', 1000 + ($i * 7) % 9000),
            'zipcode'       => fn () => sprintf('%05d', 10000 + ($i * 37) % 89000),
            'zip'           => fn () => sprintf('%05d', 10000 + ($i * 37) % 89000),

            // ---- web ------------------------------------------------------
            'url'           => fn () => 'https://example.com/page-' . $i,
            'website'       => fn () => 'https://www.' . strtolower($co) . '.example',
            'websiteurl'    => fn () => 'https://www.' . strtolower($co) . '.example',
            'link'          => fn () => 'https://example.com/link-' . $i,
            'externallink'  => fn () => 'https://example.com/external-' . $i,
            'urlsegment'    => fn () => 'mock-' . $i,
            'slug'          => fn () => 'mock-' . $i,
            'twitter'       => fn () => '@mock' . $i,
            'facebook'      => fn () => 'https://facebook.com/mock' . $i,
            'instagram'     => fn () => '@mock' . $i,
            'linkedin'      => fn () => 'https://linkedin.com/in/mock' . $i,

            // ---- content --------------------------------------------------
            'title'         => fn () => $p['titles'][$i % count($p['titles'])] . ' ' . $i,
            'name'          => fn () => $p['titles'][$i % count($p['titles'])] . ' ' . $i,
            'heading'       => fn () => $p['titles'][$i % count($p['titles'])] . ' ' . $i,
            'subtitle'      => fn () => 'Subtitle for item ' . $i,
            'tagline'       => fn () => 'A tagline for #' . $i,
            'summary'       => fn () => 'A short summary of mock item number ' . $i . '.',
            'description'   => fn () => 'Mock description for item ' . $i . '. ' . $p['lorem'],
            'shortdescription' => fn () => 'Short description #' . $i,
            'content'       => fn () => $this->generateRichHtmlContent($i),
            'body'          => fn () => $this->generateRichHtmlContent($i),
            'bio'           => fn () => 'Bio for mock person #' . $i . '. ' . $p['lorem'],
            'about'         => fn () => 'About mock entry #' . $i . '. ' . $p['lorem'],
            'notes'         => fn () => 'Internal notes for #' . $i,
            'comment'       => fn () => 'Comment #' . $i,
            'comments'      => fn () => 'Comments for #' . $i,
            'message'       => fn () => 'Mock message body #' . $i,
            'excerpt'       => fn () => 'Excerpt #' . $i . '. ' . substr($p['lorem'], 0, 100) . '...',
            'keywords'      => fn () => 'mock, sample, test, item' . $i,
            'tags'          => fn () => 'mock, sample, item' . $i,

            // ---- numbers / commerce --------------------------------------
            'price'         => fn () => round(9.99 + $i * 3.5, 2),
            'cost'          => fn () => round(5 + $i * 2, 2),
            'rrp'           => fn () => round(14.99 + $i * 4, 2),
            'amount'        => fn () => round($i * 12.5, 2),
            'subtotal'      => fn () => round($i * 12.5, 2),
            'total'         => fn () => round($i * 13.7, 2),
            'tax'           => fn () => round($i * 1.95, 2),
            'gst'           => fn () => round($i * 1.95, 2),
            'discount'      => fn () => round($i * 0.5, 2),
            'quantity'      => fn () => $i,
            'qty'           => fn () => $i,
            'stock'         => fn () => 100 - $i,
            'stocklevel'    => fn () => 100 - $i,
            'weight'        => fn () => round($i * 0.4, 2),
            'height'        => fn () => $i * 10,
            'width'         => fn () => $i * 5,
            'length'        => fn () => $i * 7,
            'depth'         => fn () => $i * 3,
            'age'           => fn () => 18 + $i,
            'rating'        => fn () => $i % 6,
            'score'         => fn () => $i * 7,
            'sort'          => fn () => $i,
            'sortorder'     => fn () => $i,
            'sortindex'     => fn () => $i,

            // ---- identifiers ---------------------------------------------
            'sku'           => fn () => 'SKU-' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
            'reference'     => fn () => 'REF-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'referencenumber' => fn () => 'REF-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'code'          => fn () => 'CODE-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'barcode'       => fn () => '978' . str_pad((string) $i, 10, '0', STR_PAD_LEFT),
            'isbn'          => fn () => '978-' . str_pad((string) $i, 10, '0', STR_PAD_LEFT),

            // ---- dates ----------------------------------------------------
            'birthdate'     => fn () => $this->fakeDate(-(20 + $i) * 365),
            'dob'           => fn () => $this->fakeDate(-(20 + $i) * 365),
            'dateofbirth'   => fn () => $this->fakeDate(-(20 + $i) * 365),
            'startdate'     => fn () => $this->fakeDate($i * -7),
            'enddate'       => fn () => $this->fakeDate($i * 7),
            'expirydate'    => fn () => $this->fakeDate($i * 30),
            'publishdate'   => fn () => $this->fakeDate(-$i * 5),
            'date'          => fn () => $this->fakeDate(-$i),

            // ---- visual / misc -------------------------------------------
            'colour'        => fn () => $p['colours'][$i % count($p['colours'])],
            'color'         => fn () => $p['colours'][$i % count($p['colours'])],
            'icon'          => fn () => 'fa-' . ['star', 'heart', 'check', 'leaf', 'cog'][$i % 5],
            'language'      => fn () => ['en', 'mi', 'fr', 'de', 'es'][$i % 5],
            'locale'        => fn () => ['en_NZ', 'en_AU', 'en_US', 'en_GB'][$i % 4],
            'timezone'      => fn () => 'Pacific/Auckland',
            'currency'      => fn () => ['NZD', 'AUD', 'USD', 'EUR', 'GBP'][$i % 5],
            'status'        => fn () => ['Active', 'Inactive', 'Pending', 'Archived'][$i % 4],

            // ---- geo ------------------------------------------------------
            'latitude'      => fn () => round(-36.85 + ($i * 0.01), 6),
            'longitude'     => fn () => round(174.76 + ($i * 0.01), 6),
            'lat'           => fn () => round(-36.85 + ($i * 0.01), 6),
            'lng'           => fn () => round(174.76 + ($i * 0.01), 6),
            'lon'           => fn () => round(174.76 + ($i * 0.01), 6),
        ];

        // exact match wins
        if (isset($map[$n])) {
            return $map[$n]();
        }

        // suffix / contains fallback for compound names like
        // 'CustomerFirstName', 'BillingAddress', 'ContactEmail'
        foreach ($map as $key => $fn) {
            if (str_ends_with($n, $key) || str_contains($n, $key)) {
                return $fn();
            }
        }

        return null;
    }

    protected function guessByType(string $type, string $name, int $i)
    {
        // Check for custom color field types BEFORE generic type matching
        // This prevents generic 'colour' name matching from overriding specific field types
        if (str_contains($type, 'Colour') || str_contains($type, 'Color')) {
            // Let the specific field type use its defaults
            return null;
        }

        // strip Varchar(255), Decimal(9,2) etc. for the switch
        $base = preg_replace('/\(.*\)/', '', $type);

        switch ($base) {
            case 'Boolean':
            case DBBoolean::class:
                return $i % 2;

            case 'Int':
            case 'BigInt':
            case DBInt::class:
            case DBBigInt::class:
                return $i;

            case 'Float':
            case 'Double':
            case 'Decimal':
            case 'Currency':
            case 'Percentage':
            case DBFloat::class:
            case DBDouble::class:
            case DBDecimal::class:
            case DBCurrency::class:
            case DBPercentage::class:
                return round($i * 1.5, 2);

            case 'Date':
            case DBDate::class:
                return $this->fakeDate(-$i);

            case 'Datetime':
            case 'SS_Datetime':
            case DBDatetime::class:
                return $this->fakeDateTime(-$i);

            case 'Time':
            case DBTime::class:
                return sprintf('%02d:%02d:00', ($i * 2) % 24, ($i * 5) % 60);

            case 'Year':
            case DBYear::class:
                return 2000 + ($i % 26);

            case 'HTMLText':
            case 'HTMLVarchar':
            case DBHTMLText::class:
            case DBHTMLVarchar::class:
                return $this->generateRichHtmlContent($i);

            case 'Text':
            case DBText::class:
                return $this->dataPools()['lorem'] . ' (' . $name . ' #' . $i . ')';

            case 'Email':
            case DBEmail::class:
                return sprintf('mock%d@example.com', $i);

            case 'Phone':
                return $this->fakePhone($i);

            case 'Enum':
                // parse "Enum('A,B,C', 'A')" or "Enum("A,B,C", "A")" or without quotes
                if (preg_match("/Enum\s*\(\s*['\"]([^'\"]+)['\"]/", $type, $m)) {
                    $values = array_map('trim', explode(',', $m[1]));
                    if (count($values) > 0) {
                        return $values[$i % count($values)];
                    }
                }
                // Try without quotes: Enum(A,B,C)
                if (preg_match("/Enum\s*\(([^,)]+(?:,[^)]+)*)\)/", $type, $m)) {
                    $values = array_map('trim', explode(',', $m[1]));
                    if (count($values) > 0) {
                        return $values[$i % count($values)];
                    }
                }
                return null; // let the ORM fall back to default

            case 'Varchar':
            case DBVarchar::class:
            default:
                return $name . ' ' . $i;
        }
    }

    // =================================================================
    //  Helpers
    // =================================================================

    /**
     * Generate rich HTML content with various elements.
     */
    protected function generateRichHtmlContent(int $i): string
    {
        $p = $this->dataPools();

        // Vary the content based on index
        $templates = [
            // Template 1: Heading + paragraph + list
            '<h3>%s</h3><p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li></ul><p>For more information, visit <a href="%s">our website</a>.</p>',

            // Template 2: Paragraph + heading + paragraph + link
            '<p>%s</p><h3>%s</h3><p>This is an important update about %s. We recommend that you <a href="%s">read the full details</a> for more information.</p><p><strong>Note:</strong> %s</p>',

            // Template 3: Heading + ordered list + paragraph
            '<h3>Key Points About %s</h3><ol><li><strong>First:</strong> %s</li><li><strong>Second:</strong> %s</li><li><strong>Third:</strong> %s</li></ol><p>Learn more at <a href="%s" target="_blank">this link</a>.</p>',

            // Template 4: Multiple paragraphs with emphasis
            '<p><strong>%s</strong></p><p>%s</p><p><em>Important:</em> %s For assistance, please <a href="mailto:info@example.com">contact us</a>.</p>',

            // Template 5: Heading + paragraph + bullet list + link
            '<h3>About %s</h3><p>%s</p><ul><li>Feature: %s</li><li>Benefit: %s</li><li>Usage: %s</li></ul><p>More details available <a href="%s">here</a>.</p>',
        ];

        $template = $templates[$i % count($templates)];

        $title = $p['titles'][$i % count($p['titles'])];
        $lorem = $p['lorem'];
        $url = 'https://example.com/page-' . $i;

        $items = [
            'Planning and preparation',
            'Community engagement',
            'Resource allocation',
            'Implementation strategy',
            'Monitoring and evaluation',
            'Risk assessment',
            'Quality assurance',
            'Stakeholder communication',
        ];

        $phrases = [
            'This ensures effective outcomes',
            'It provides comprehensive coverage',
            'This approach delivers results',
            'The process is straightforward',
            'This method is proven effective',
            'Results can be measured',
            'Benefits are clearly defined',
            'Implementation is streamlined',
        ];

        // Fill in template with appropriate content
        switch ($i % count($templates)) {
            case 0:
                return sprintf(
                    $template,
                    $title,
                    $lorem,
                    $items[$i % count($items)],
                    $items[($i + 1) % count($items)],
                    $items[($i + 2) % count($items)],
                    $url
                );

            case 1:
                return sprintf(
                    $template,
                    $lorem,
                    $title,
                    strtolower($title),
                    $url,
                    $phrases[$i % count($phrases)]
                );

            case 2:
                return sprintf(
                    $template,
                    $title,
                    $phrases[$i % count($phrases)],
                    $phrases[($i + 1) % count($phrases)],
                    $phrases[($i + 2) % count($phrases)],
                    $url
                );

            case 3:
                return sprintf(
                    $template,
                    $title,
                    $lorem,
                    $phrases[$i % count($phrases)]
                );

            case 4:
            default:
                return sprintf(
                    $template,
                    $title,
                    $lorem,
                    $items[$i % count($items)],
                    $phrases[$i % count($phrases)],
                    $items[($i + 1) % count($items)],
                    $url
                );
        }
    }

    protected function fakePhone(int $i): string
    {
        return sprintf('+64 21 %03d %04d', ($i * 13) % 1000, ($i * 137) % 10000);
    }

    protected function fakeDate(int $offsetDays): string
    {
        return date('Y-m-d', strtotime(sprintf('%+d days', $offsetDays)));
    }

    protected function fakeDateTime(int $offsetDays): string
    {
        return date('Y-m-d H:i:s', strtotime(sprintf('%+d days', $offsetDays)));
    }

    /**
     * Override this (or extend it via Configurable) to localise the data pools.
     */
    protected function dataPools(): array
    {
        return [
            'firstNames' => ['Ava', 'Liam', 'Mia', 'Noah', 'Zoe', 'Ethan', 'Aroha', 'Tane', 'Ruby', 'Hugo', 'Olivia', 'Arlo'],
            'lastNames'  => ['Smith', 'Walker', 'Tane', 'Patel', 'Nguyen', 'Williams', 'Brown', 'Singh', 'Wilson', 'Cohen', 'Murphy', 'Anderson'],
            'companies'  => ['Acme', 'Globex', 'Initech', 'Soylent', 'Umbrella', 'Hooli', 'Sunnyside', 'PiedPiper', 'Wayne', 'Stark', 'Wonka', 'Vandelay'],
            'jobTitles'  => ['Manager', 'Developer', 'Designer', 'Analyst', 'Director', 'Coordinator', 'Specialist', 'Engineer'],
            'departments' => ['Sales', 'Marketing', 'Engineering', 'Support', 'HR', 'Finance', 'Operations'],
            'streets'    => ['Queen Street', 'Karangahape Road', 'Ponsonby Road', 'Symonds Street', 'High Street', 'Victoria Street', 'Wellesley Street'],
            'cities'     => ['Auckland', 'Wellington', 'Christchurch', 'Hamilton', 'Dunedin', 'Tauranga', 'Napier'],
            'states'     => ['Auckland', 'Otago', 'Canterbury', 'Wellington', 'Waikato', 'Bay of Plenty'],
            'countries'  => ['New Zealand', 'Australia', 'United Kingdom', 'United States', 'Canada', 'Germany'],
            'titles'     => ['Mock Item', 'Sample Entry', 'Demo Record', 'Test Title', 'Example Item'],
            'colours'    => ['#ff0000', '#00ff00', '#0000ff', '#ffaa00', '#aa00ff', '#00aaff'],
            'lorem'      => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
        ];
    }

    protected function writeObject(DataObject $obj, string $className, bool $skipValidation = true): int
    {
        try {
            // write($showDebug, $forceInsert, $forceWrite, $writeComponents, $skipValidation)
            $id = $obj->write(false, false, false, false, $skipValidation); // Skip validation
            if ($obj->hasMethod('publishRecursive')) {
                $obj->publishRecursive();
            }
            if ($obj->hasMethod('flushCache')) {
                $obj->flushCache();
            }
            return $id;
        } catch (ValidationException $e) {
            DB::alteration_message("Validation error for {$className}: " . $e->getMessage(), 'error');
            return 0;
        } catch (Throwable $e) {
            DB::alteration_message("Error writing {$className}: " . $e->getMessage(), 'error');
            return 0;
        }
    }
}
