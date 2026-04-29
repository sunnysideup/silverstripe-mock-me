<?php

namespace Sunnysideup\MockMe;

use ReflectionClass;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Security\MemberPassword;
use SilverStripe\Security\Permission;
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
        DataObject::class,
        MemberPassword::class,
        Permission::class,
    ];

    /**
     * Fields that should never be written to directly (set by the ORM).
     */
    private static array $fields_to_skip = [
        'ID', 'ClassName', 'LastEdited', 'Created', 'Version',
    ];

    /**
     * ClassName => array of IDs we created in phase 1.
     * Used as the candidate pool when wiring relationships.
     *
     * @var array<string, int[]>
     */
    protected array $createdIds = [];

    // =================================================================
    //  Entry point
    // =================================================================

    public function run(): void
    {
        $classes = $this->getEligibleClasses();

        DB::alteration_message('=== Phase 1: creating base records ===', 'created');
        foreach ($classes as $className) {
            $this->createRecordsForClass($className);
        }

        DB::alteration_message('=== Phase 2: wiring relationships ===', 'created');
        foreach ($classes as $className) {
            $this->wireRelationshipsForClass($className);
        }

        DB::alteration_message('Mock data generation complete.', 'created');
    }

    // =================================================================
    //  Class discovery
    // =================================================================

    protected function getEligibleClasses(): array
    {
        $exclude = (array) Config::inst()->get(static::class, 'classes_to_exclude');
        $all = ClassInfo::subclassesFor(DataObject::class, false);
        $out = [];
        foreach ($all as $className) {
            if (in_array($className, $exclude, true)) {
                continue;
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

        $created = 0;
        for ($i = 1; $i <= $count; $i++) {
            try {
                /** @var DataObject $obj */
                $obj = Injector::inst()->create($className);

                foreach ($dbFields as $fieldName => $fieldType) {
                    if (in_array($fieldName, $skip, true)) {
                        continue;
                    }
                    $value = $this->generateValueForField($fieldName, (string) $fieldType, $i);
                    if ($value !== null) {
                        $obj->$fieldName = $value;
                    }
                }

                $id = (int) $obj->write();
                $this->createdIds[$className][] = $id;
                $created++;
            } catch (Throwable $e) {
                DB::alteration_message(
                    "Failed creating record {$i} for {$className}: {$e->getMessage()}",
                    'error'
                );
            }
        }

        DB::alteration_message("  + {$created} × {$className}", 'created');
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
            $obj = DataObject::get_by_id($className, $id);
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
                $candidates = $this->candidateIdsFor($relatedClass);
                if (!$candidates) {
                    continue;
                }
                $field = $relationName . 'ID';
                $obj->$field = $candidates[array_rand($candidates)];
                $changed = true;
            }

            if ($changed) {
                try {
                    $obj->write();
                } catch (Throwable $e) {
                    DB::alteration_message(
                        "Failed writing has_one for {$className}#{$id}: {$e->getMessage()}",
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

        try {
            $relation = $obj->$relationName();
        } catch (Throwable $e) {
            return;
        }

        $n = min($perRecord, count($candidates));
        $keys = $n === 1 ? [array_rand($candidates)] : (array) array_rand($candidates, $n);

        foreach ($keys as $key) {
            $relatedId = (int) $candidates[$key];
            $other = DataObject::get_by_id($relatedClass, $relatedId);
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

    // =================================================================
    //  Field value generation
    // =================================================================

    protected function generateValueForField(string $name, string $type, int $index)
    {
        // 1. try to guess from the field name (most specific signal)
        $byName = $this->guessByName($name, $index);
        if ($byName !== null) {
            return $byName;
        }
        // 2. fall back to the SilverStripe field type
        return $this->guessByType($type, $name, $index);
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
            'content'       => fn () => '<p>' . $p['lorem'] . '</p><p>Mock entry #' . $i . '</p>',
            'body'          => fn () => '<p>' . $p['lorem'] . '</p>',
            'bio'           => fn () => 'Bio for mock person #' . $i . '.',
            'about'         => fn () => 'About mock entry #' . $i . '.',
            'notes'         => fn () => 'Internal notes for #' . $i,
            'comment'       => fn () => 'Comment #' . $i,
            'comments'      => fn () => 'Comments for #' . $i,
            'message'       => fn () => 'Mock message body #' . $i,
            'excerpt'       => fn () => 'Excerpt #' . $i,
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
        // strip Varchar(255), Decimal(9,2) etc. for the switch
        $base = preg_replace('/\(.*\)/', '', $type);

        switch ($base) {
            case 'Boolean':
                return $i % 2;

            case 'Int':
            case 'BigInt':
                return $i;

            case 'Float':
            case 'Double':
            case 'Decimal':
            case 'Currency':
            case 'Percentage':
                return round($i * 1.5, 2);

            case 'Date':
                return $this->fakeDate(-$i);

            case 'Datetime':
            case 'SS_Datetime':
                return $this->fakeDateTime(-$i);

            case 'Time':
                return sprintf('%02d:%02d:00', ($i * 2) % 24, ($i * 5) % 60);

            case 'Year':
                return 2000 + ($i % 26);

            case 'HTMLText':
            case 'HTMLVarchar':
                return '<p>' . $this->dataPools()['lorem'] . '</p>';

            case 'Text':
                return $this->dataPools()['lorem'] . ' (' . $name . ' #' . $i . ')';

            case 'Email':
                return sprintf('mock%d@example.com', $i);

            case 'Phone':
                return $this->fakePhone($i);

            case 'Enum':
                // parse "Enum('A,B,C', 'A')" or with double quotes
                if (preg_match("/Enum\(['\"]([^'\"]+)['\"]/", $type, $m)) {
                    $values = array_map('trim', explode(',', $m[1]));
                    return $values[$i % count($values)];
                }
                return null; // let the ORM fall back to default

            case 'Varchar':
            default:
                return $name . ' ' . $i;
        }
    }

    // =================================================================
    //  Helpers
    // =================================================================

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
}
