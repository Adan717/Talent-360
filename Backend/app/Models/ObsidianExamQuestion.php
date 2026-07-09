<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ObsidianExamQuestion extends Model
{
    protected $fillable = [
        'exam_id',
        'question_text',
        'options',
        'correct_option'
    ];

    protected $casts = [
        'options' => 'array'
    ];

    public function exam()
    {
        return $this->belongsTo(ObsidianExam::class, 'exam_id');
    }
}
