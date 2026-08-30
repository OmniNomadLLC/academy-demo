<?php

namespace Database\Seeders;

use App\Models\AssessmentQuestion;
use App\Models\AssessmentTemplate;
use App\Support\Assessments\SkillCategory;
use Illuminate\Database\Seeder;

class AssessmentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $template = AssessmentTemplate::updateOrCreate(
            ['name' => 'Initial Speaking & Listening Assessment', 'region' => 'uk'],
            [
                'description' => 'Default intake questionnaire for UK assessments.',
                'is_active' => true,
            ]
        );

        $questions = [
            SkillCategory::SPEAKING_LISTENING => [
                'What is your name?',
                'How do you spell it?',
                'Where are you from?',
                'When did you come to the UK?',
                'Where do you live now?',
                'Do you have children? How old are they?',
                'Describe your area.',
                'Have you studied English before?',
                'Tell me about this.',
                'What day is it today?',
                'What time do you usually wake up and go to bed?',
                'What did you do yesterday?',
                'What will you do tomorrow?',
                'Have you worked before? What did you do?',
                'What are you good at? (skills)',
                'Why is English important?',
            ],
            SkillCategory::TO_LEARN => [
                'Motivation to learn',
                'Motivation to find work',
                'Communication confidence',
                'Punctuality and attitude (arrived on time, prepared)',
                'IT skills',
                'Reading',
                'Writing',
                'Speaking and listening',
                'Use present tense accurately (simple / continuous)',
                'Use past tense accurately (past simple)',
                'Use future tense accurately (will / continuous)',
            ],
        ];

        foreach ($questions as $category => $items) {
            $sort = 1;
            foreach ($items as $text) {
                AssessmentQuestion::updateOrCreate(
                    [
                        'assessment_template_id' => $template->id,
                        'section' => SkillCategory::label($category),
                        'skill_category' => $category,
                        'question_text' => $text,
                    ],
                    [
                        'sort_order' => $sort++,
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
