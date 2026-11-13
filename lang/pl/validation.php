<?php

return [
    'required' => 'Pole :attribute jest wymagane.',
    'string' => 'Pole :attribute musi zawierać tekst.',
    'email' => 'Podaj prawidłowy adres e-mail.',
    'unique' => 'Podana wartość pola :attribute jest już używana.',
    'current_password' => 'Podane hasło jest nieprawidłowe.',
    'prohibited' => 'Pole :attribute nie może być przesłane w tym formularzu.',
    'confirmed' => 'Powtórzone hasło nie jest zgodne z hasłem.',
    'min' => ['string' => 'Pole :attribute musi zawierać co najmniej :min znaków.'],
    'max' => [
        'string' => 'Pole :attribute może zawierać maksymalnie :max znaków.',
    ],
    'custom' => [
        'phone' => ['regex' => 'Podaj numer z kierunkowym kraju, np. +48 600 700 800.'],
        'email' => [
            'unique' => 'Ten adres e-mail jest już używany. Podaj inny.',
        ],
    ],
    'attributes' => [
        'name' => 'imię i nazwisko',
        'first_name' => 'imię',
        'last_name' => 'nazwisko',
        'email' => 'adres e-mail',
        'password' => 'hasło',
        'phone' => 'telefon',
    ],
];
