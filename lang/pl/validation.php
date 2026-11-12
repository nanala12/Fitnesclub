<?php

return [
    'required' => 'Pole :attribute jest wymagane.',
    'string' => 'Pole :attribute musi zawierać tekst.',
    'email' => 'Podaj prawidłowy adres e-mail.',
    'unique' => 'Podana wartość pola :attribute jest już używana.',
    'current_password' => 'Podane hasło jest nieprawidłowe.',
    'max' => [
        'string' => 'Pole :attribute może zawierać maksymalnie :max znaków.',
    ],
    'custom' => [
        'email' => [
            'unique' => 'Ten adres e-mail jest już używany. Podaj inny.',
        ],
    ],
    'attributes' => [
        'name' => 'imię i nazwisko',
        'email' => 'adres e-mail',
        'password' => 'hasło',
    ],
];
