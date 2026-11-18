# Payment tests

`payment_math.php` checks the balance rules without a database.

`payment_http.php` exercises the live cashier and admin routes against a disposable database. It refuses to run unless `HP_DB_NAME=hungry_potters_payment_test`. Start with a fresh test database made from `database/database.sql`, replacing only the database name. Seeded demo accounts are used by the HTTP test.

In PowerShell from the repository root:

```powershell
$schemaText = Get-Content 'hungry-potters/database/database.sql' -Raw
$schemaText.Replace('hungry_potters', 'hungry_potters_payment_test') | & 'C:\xampp\mysql\bin\mysql.exe' -u root
New-Item -ItemType Directory -Path 'hungry-potters/tests/sessions' -Force | Out-Null
$env:HP_DB_NAME = 'hungry_potters_payment_test'
& 'C:\xampp\php\php.exe' -d 'session.save_path=C:\xampp\htdocs\Hungry-Potters-\hungry-potters\tests\sessions' -S 127.0.0.1:8765 -t 'C:\xampp\htdocs\Hungry-Potters-\hungry-potters'
```

In a second PowerShell window:

```powershell
$env:HP_DB_NAME = 'hungry_potters_payment_test'
$env:HP_TEST_BASE_URL = 'http://127.0.0.1:8765'
& 'C:\xampp\php\php.exe' 'hungry-potters/tests/payment_math.php'
& 'C:\xampp\php\php.exe' 'hungry-potters/tests/payment_http.php'
```

Use a newly created test database for each run. The HTTP test creates orders and shifts and does not remove them.
