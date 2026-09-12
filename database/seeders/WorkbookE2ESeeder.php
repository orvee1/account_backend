<?php
namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Services\ChartAccountService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class WorkbookE2ESeeder extends Seeder
{
    /*
    |--------------------------------------------------------------------------
    | Workbook QA Credentials
    |--------------------------------------------------------------------------
    |
    | These credentials are intended only for the isolated testing database.
    |
    */

    private const COMPANY_NAME =
        'Workbook E2E Company';

    private const USER_EMAIL =
        'workbook.e2e@example.test';

    private const USER_PASSWORD =
        '123456';

    private const USER_PHONE =
        '01799990001';

    private const WAREHOUSE_NAME =
        'QA Warehouse';

    /*
    |--------------------------------------------------------------------------
    | Run Seeder
    |--------------------------------------------------------------------------
    */

    public function run(): void
    {
        if (!app()->environment('testing') || DB::connection()->getDatabaseName() !== 'account_api_testing') {
            throw new \RuntimeException('WorkbookE2ESeeder requires the testing environment and account_api_testing database.');
        }
        DB::transaction(function (): void {
            $existing = Company::where('name', self::COMPANY_NAME)->first();
            if ($existing) {
                foreach (['products', 'customers', 'vendors', 'journal_entries'] as $table) {
                    if (DB::table($table)->where('company_id', $existing->id)->exists()) {
                        throw new \RuntimeException('Workbook company already contains test data. Use php qa/browser-fixture.php --workbook from the workspace root to create a fresh isolated company.');
                    }
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 1. Create Dedicated QA Company
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | We do NOT use company_id = 1.
            |
            | DatabaseSeeder already creates demo products, customers,
            | vendors and accounting activity for company 1.
            |
            | The workbook test must begin from a clean company so that:
            |
            | Inventory
            | AR
            | AP
            | Sales
            | COGS
            | Cash
            | Bank
            |
            | all begin at zero.
            |
            */

            $company = Company::query()
                ->updateOrCreate(
                    [
                        'name' =>
                        self::COMPANY_NAME,
                    ],
                    [
                        'status' =>
                        'active',
                    ]
                );

            /*
            |--------------------------------------------------------------------------
            | 2. Create QA Login User
            |--------------------------------------------------------------------------
            |
            | CompanyUser has a password mutator, but Hash::make() is used
            | explicitly here so the intent is clear.
            |
            */

            $companyUser = CompanyUser::query()
                ->updateOrCreate(
                    [
                        'company_id' =>
                        $company->id,

                        'email'      =>
                        self::USER_EMAIL,
                    ],
                    [
                        'name'         =>
                        'Workbook QA User',

                        'phone_number' =>
                        self::USER_PHONE,

                        'password'     =>
                        Hash::make(
                            self::USER_PASSWORD
                        ),

                        'role'         =>
                        'owner',

                        'status'       =>
                        'active',

                        'is_primary'   =>
                        true,

                        'permissions'  =>
                        null,
                    ]
                );

            /*
            |--------------------------------------------------------------------------
            | 3. Default Chart of Accounts
            |--------------------------------------------------------------------------
            |
            | Creates the same accounting structure used by the real system:
            |
            | Cash
            | Bank
            | Accounts Receivable
            | Inventory
            | Accounts Payable
            | Opening Balances
            | Sales Revenue
            | COGS
            | etc.
            |
            | ChartAccountService is idempotent for a company, so rerunning
            | the seeder will not duplicate the COA tree.
            |
            */

            app(
                ChartAccountService::class
            )->seedDefaultForCompany(
                (int) $company->id
            );

            /*
            |--------------------------------------------------------------------------
            | 4. Dedicated Warehouse
            |--------------------------------------------------------------------------
            |
            | Workbook product opening stock and all later inventory
            | transactions will use this warehouse.
            |
            */

            $warehouse = Warehouse::query()
                ->updateOrCreate(
                    [
                        'company_id' =>
                        $company->id,

                        'name'       =>
                        self::WAREHOUSE_NAME,
                    ],
                    [
                        'is_default' =>
                        true,
                    ]
                );

            /*
            |--------------------------------------------------------------------------
            | 5. Ensure Only This Warehouse Is Default
            |--------------------------------------------------------------------------
            |
            | This makes warehouse selection deterministic even if this seeder
            | is executed more than once in the testing database.
            |
            */

            Warehouse::query()
                ->where(
                    'company_id',
                    $company->id
                )
                ->where(
                    'id',
                    '!=',
                    $warehouse->id
                )
                ->update([
                    'is_default' =>
                    false,
                ]);

            /*
            |--------------------------------------------------------------------------
            | 6. VAT Setting
            |--------------------------------------------------------------------------
            |
            | Current purchase/sales services read is_vat_registered.
            |
            | The workbook scenarios currently use 0 VAT, but setting this
            | explicitly prevents environment-dependent behaviour.
            |
            */

            $existingSetting =
            DB::table(
                'company_settings'
            )
                ->where(
                    'company_id',
                    $company->id
                )
                ->where(
                    'key',
                    'is_vat_registered'
                )
                ->first();

            if ($existingSetting) {
                DB::table(
                    'company_settings'
                )
                    ->where(
                        'id',
                        $existingSetting->id
                    )
                    ->update([
                        'value'      =>
                        'true',

                        'updated_at' =>
                        now(),
                    ]);
            } else {
                DB::table(
                    'company_settings'
                )
                    ->insert([
                        'company_id' =>
                        $company->id,

                        'key'        =>
                        'is_vat_registered',

                        'value'      =>
                        'true',

                        'created_at' =>
                        now(),

                        'updated_at' =>
                        now(),
                    ]);
            }

            /*
            |--------------------------------------------------------------------------
            | 7. Ensure Base Unit of Measure
            |--------------------------------------------------------------------------
            |
            | AddProductForm defaults to:
            |
            | Piece / pcs
            |
            | ProductService can create this automatically, but creating it
            | here makes the E2E environment deterministic and lets the UOM
            | dropdown/API already contain the expected base unit.
            |
            */

            $piece = UnitOfMeasure::query()
                ->where(
                    'name',
                    'Piece'
                )
                ->first();

            if (! $piece) {
                UnitOfMeasure::query()
                    ->create([
                        'name'   =>
                        'Piece',

                        'symbol' =>
                        'pcs',
                    ]);
            } elseif (
                ! $piece->symbol
            ) {
                $piece->update([
                    'symbol' =>
                    'pcs',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Seeder Output
            |--------------------------------------------------------------------------
            */

            if ($this->command) {
                $this->command->newLine();

                $this->command->info(
                    'Workbook E2E environment created successfully.'
                );

                $this->command->line(
                    "Company ID: {$company->id}"
                );

                $this->command->line(
                    'Company: ' .
                    self::COMPANY_NAME
                );

                $this->command->line(
                    'Warehouse: ' .
                    self::WAREHOUSE_NAME
                );

                $this->command->line(
                    'Login Email: ' .
                    self::USER_EMAIL
                );

                $this->command->line(
                    'Login Password: ' .
                    self::USER_PASSWORD
                );

                $this->command->newLine();
            }
        });
    }
}
