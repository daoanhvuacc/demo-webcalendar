<?php

namespace Modules\Schedule\Entities;

use Illuminate\Database\Eloquent\Model;

class ScheduleEvent extends Model
{

    protected $table = 'makeit__schedules_event';
    protected $fillable = ['teacher_id','date_id','slot_id','class_name','subject_code','start_date','end_date','start_time','end_time','day_name'];

    public function teacher(){
        return $this->belongsTo(Teacher::class);
    }
}
