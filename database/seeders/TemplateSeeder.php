<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
        ['title' => 'Modern Professional', 'color' => '#2563eb'],
        ['title' => 'Creative Bio', 'color' => '#db2777'],
        ['title' => 'Edu Green', 'color' => '#059669'],
        ['title' => 'Dark Tech', 'color' => '#1e293b'],
        ['title' => 'Soft Pastel', 'color' => '#f59e0b'],
    ];

    foreach ($templates as $temp) {
        \App\Models\Template::create([
            'title' => $temp['title'],
            'default_settings' => [
                'primary_color' => $temp['color'],
                'font_family' => 'Inter',
                'shape_type' => 'geometric' // سنستخدم هذا في الفرونت للرسم
            ]
        ]);
    }
    }
}
