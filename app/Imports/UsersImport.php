<?php

namespace App\Imports;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class UsersImport implements ToCollection, WithChunkReading, WithHeadingRow
{
    public int $created = 0;

    public int $skipped = 0;

    /** @var array<int, string> */
    public array $errors = [];

    public function collection(Collection $rows): void
    {
        $normalizedRows = $rows->map(function (mixed $row): array {
            return $row instanceof Collection ? $row->all() : $row;
        });
        $emails = $normalizedRows
            ->pluck('email')
            ->map(fn (mixed $email): string => strtolower(trim((string) $email)))
            ->filter()
            ->values();
        $existingEmails = User::query()->whereIn('email', $emails)->pluck('email')->map(fn (string $email): string => strtolower($email));
        $seenEmails = $existingEmails->flip();
        $records = [];
        $now = now();

        foreach ($normalizedRows as $row) {
            $data = [
                'name' => trim((string) ($row['name'] ?? '')),
                'email' => strtolower(trim((string) ($row['email'] ?? ''))),
                'password' => (string) ($row['password'] ?? ''),
                'role' => trim((string) ($row['role'] ?? 'admin')),
            ];
            $validator = Validator::make($data, [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
                'role' => ['required', 'in:admin,super_admin'],
            ]);

            if ($validator->fails()) {
                $this->skipped++;
                $this->errors[] = 'Baris '.($row['__row'] ?? '?').': '.$validator->errors()->first();

                continue;
            }

            if ($seenEmails->has($data['email'])) {
                $this->skipped++;
                $this->errors[] = 'Email '.$data['email'].' sudah terdaftar atau duplikat.';

                continue;
            }

            $seenEmails->put($data['email'], true);
            $records[] = [
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => $data['role'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($records !== []) {
            User::query()->insert($records);
            $this->created += count($records);
        }
    }

    public function chunkSize(): int
    {
        return 500;
    }
}
