<?php

namespace App\Support;

final class DailyMessageCategories
{
    public const LABELS = [
        'mother' => ['en' => 'Mother', 'bn' => 'মা'],
        'father' => ['en' => 'Father', 'bn' => 'বাবা'],
        'wife' => ['en' => 'Life partner', 'bn' => 'জীবনসঙ্গী'],
        'children' => ['en' => 'Children', 'bn' => 'সন্তান'],
        'family' => ['en' => 'Family', 'bn' => 'পরিবার'],
        'friend' => ['en' => 'Friends', 'bn' => 'বন্ধু'],
        'self' => ['en' => 'For you', 'bn' => 'নিজের জন্য'],
        'hard' => ['en' => 'Hard days', 'bn' => 'কঠিন দিন'],
        'pride' => ['en' => 'Pride', 'bn' => 'সম্মান'],
        'payday' => ['en' => 'Salary day', 'bn' => 'বেতনের দিন'],
        'savings' => ['en' => 'Savings', 'bn' => 'সঞ্চয়'],
        'motivation' => ['en' => 'Motivation', 'bn' => 'অনুপ্রেরণা'],
        'money_management' => ['en' => 'Money Management', 'bn' => 'অর্থ ব্যবস্থাপনা'],
        'life_lessons' => ['en' => 'Life Lessons', 'bn' => 'জীবনের শিক্ষা'],
        'health' => ['en' => 'Health', 'bn' => 'স্বাস্থ্য'],
    ];

    public static function codes(): array
    {
        return array_keys(self::LABELS);
    }
}
