# Scrapper

## Cara Menggunakan:

1. Isi `config.json`:
```json
{
    "database": {
        "host": "hostname",
        "dbname": "dbname",
        "username": "username",
        "password": "password"
    },
    "env": "staging"
}
```

Untuk env, isi dengan `staging` jika dilingkungan staging dan `production` jika untuk lingkungan production.

2. Jalankan dengan menggunakan command `php script.php` untuk langsung menulis di database, atau menggunakan `php script-old.php` untuk mendapatkan file `*.sql` yg nantinya bisa dieksekusi secara terpisah

3. Gunakan params `-l` untuk menghasilkan log pada script `php script.php -l`

## File Script

a. `script.php` untuk langsung menulis ke database
b. `script-old.php` untuk mendapatkan file `*.sql` dan bisa dieksekusi terpisah.