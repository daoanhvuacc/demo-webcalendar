<?php
/**
 * Created by PhpStorm.
 * User: eric
 * Date: 8/25/17
 * Time: 11:39
 */
namespace Modules\Schedule\Events\Handlers;

use Carbon\Carbon;
use DebugBar\DebugBar;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\Page\Events\PageIsCreating;
use Modules\Schedule\Events\ImportExcelSchedule;
use Modules\Schedule\Events\ReadTeacherExcelFile;
use Modules\Schedule\Repositories\ScheduleRepository;
use Modules\Schedule\Repositories\TeacherRepository;

class InsertTeacherExcelSchedule implements ShouldQueue
{
    public $tries = 1;
    public $timeout = 360;
    use InteractsWithQueue;
    /**
     * Create the event listener.
     *
     * @return void
     */
    protected $teacherRepository;
    protected $scheduleRepository;

    public function __construct(TeacherRepository $teacherRepository, ScheduleRepository $scheduleRepository)
    {
        $this->teacherRepository = $teacherRepository;
        $this->scheduleRepository = $scheduleRepository;
    }

    /**
     * Handle the event.
     *
     * @param  ImportExcelSchedule  $event
     * @return void
     */
    public function handle(ReadTeacherExcelFile $event)
    {
//        if (true) {
//            $this->release(2);
//        }
        $rowNumber = $event->rowNumber;
        $interval = $event->interval;
        $startTime = $event->startTime;

        $path = storage_path('imports')."/import.xlsx";
        $objPHPExcel = \PHPExcel_IOFactory::load($path);
        $objWorksheet = $objPHPExcel->getActiveSheet();

        //
        $hoursInDaysArray = $objWorksheet->rangeToArray($objWorksheet->getCellByColumnAndRow(2,1)->getMergeRange());
        $hoursInDays = count($hoursInDaysArray[0]);
        $daysInWeek = ['Monday','Tuesday','Wednesday','Thursday','Friday'];

        $result = [];
        $startRow = 2;
        $row = $rowNumber+$startRow;

        $teacherName = $objWorksheet->getCellByColumnAndRow(0 , $row)->getValue();
        Log::info('====================================== START INSERT TEACHER' . $teacherName.'==========================');
//        $scheduleRow = [];
        if(!empty($teacherName)){
            $n=0;
            $m=1;

            //check exist teacher name
            $teacherObject = $this->teacherRepository->findByAttributes(['name'=>$teacherName]);
            if(!$teacherObject){
                $teacherObject = $this->teacherRepository->create(['name'=>$teacherName]);
            }

            for($j=1; $j<=$hoursInDays*count($daysInWeek);$j++){
//                        $column = $objWorksheet->getCellByColumnAndRow($j,$row)->getColumn();
                $cellNum = $objWorksheet->getCellByColumnAndRow($j,$row)->getRow();
//                        $cell = $objWorksheet->getCell($column.$cellNum);

                if (!$objWorksheet->getCellByColumnAndRow( $j,$row)->isInMergeRange() || $objWorksheet->getCellByColumnAndRow( $j,$row )->isMergeRangeValueCell()) {
                    $scheduleRow[$daysInWeek[$n]][$j] = $objWorksheet->getCellByColumnAndRow($j , $row)->getValue();
                } else {
                    $mergeRange = $objWorksheet->getCellByColumnAndRow($j , $row)->getMergeRange();
                    $mergeRangeArray = explode(':',$mergeRange);
                    $scheduleRow[$daysInWeek[$n]][$j] = $objWorksheet->getCell($mergeRangeArray[0])->getValue();
                }

                $dateSchedules[$j] = $this->getDateSchedule($j,$m,$interval,$startTime);

                if($j%$hoursInDays==0){
                    $n++;
                    $m=1;
                }
                else{
                    $m++;
                }

            }
            foreach($scheduleRow as $day => $srow){
                $e=1;
                foreach($srow as $key => $value ){
                    if($value == null){
//                        unset($scheduleRow[$key]);
                    }
                    else{
                        $values = explode("\n",$value);
                        Log::info('schedule text: '.$values[0]);
                        $startDate = $dateSchedules[$key];
                        $endDate = Carbon::parse($startDate)->addMinutes($interval);

                        $scheduleStartTime = Carbon::parse($startDate)->toTimeString();
                        $scheduleEndTime = Carbon::parse($endDate)->toTimeString();

//                                Log::info('----STARTDATE' . $startDate.'--------');
//                                Log::info('----ENDDATE' . $endDate.'--------');
//
//                                Log::info('----STARTTIME' . $scheduleStartTime.'--------');
//                                Log::info('----ENDTIME' . $scheduleEndTime.'--------');

                        if(count($values) > 0){
                            $classNamesArray = json_encode($values[0]);
                            $classNamesArray = explode("\n",json_decode($classNamesArray));
                            $className = '';
                            $subjectCode = '';
                            if(count($classNamesArray)>0){
                                $className = $classNamesArray[0];
//                                if(!empty(array_last($classNamesArray))){
//                                    $subjectCode .=' '.array_last($classNamesArray);
//                                }
                            }
                            else{
                                $className = $values[0];
                            }


                            for($v = 1; $v< count($values); $v++){
                                if(!empty($values[$v]) && $values[$v] != ' '){
                                    $subjectCode .= ' '.$values[$v];
                                }
                            }
                            $scheduleData = [
                                'teacher_id'=>$teacherObject->id,
                                'subject_code'=>$subjectCode,
                                'class_name'=>$className,
                                'date_id'=> $key,
                                'slot_id'=> $e,
                                'start_date'=> $startDate,
                                'end_date'=> $endDate,
                                'start_time'=> $scheduleStartTime,
                                'end_time'=> $scheduleEndTime,
                                'day_name'=> $day,
                            ];
                            $this->scheduleRepository->create($scheduleData);
                        }
                        else{
                            $scheduleData = [
                                'teacher_id'=>$teacherObject->id,
                                'class_name'=>$value,
                                'subject_code'=>'',
                                'date_id'=> $key,
                                'slot_id'=> $e,
                                'start_date'=> $startDate,
                                'end_date'=> $endDate,
                                'start_time'=> $scheduleStartTime,
                                'end_time'=> $scheduleEndTime,
                                'day_name'=> $day,
                            ];
                            $this->scheduleRepository->create($scheduleData);
                        }


                    }
                    if($key%$hoursInDays==0){
                        $e=1;
                    }
                    else{
                        $e++;
                    }
                }
            }
        }
        else{
            //teacher name blank
        }
        Log::info('====================================== END INSERT TEACHER' . $teacherName.'==========================');
    }

    private function getDateSchedule($rowNo, $resetRowNo, $interval , $startTime){

        $firstDayOfWeek = Carbon::now()->startOfWeek();
        $format = 'Y-m-d';
        $full_format = 'Y-m-d h:m:s';

        $monday = $firstDayOfWeek->toDateString();
        $tuesday = Carbon::parse($monday)->addDay(1)->toDateString();
        $wednesday = Carbon::parse($monday)->addDays(2)->toDateString();
        $thursday = Carbon::parse($monday)->addDays(3)->toDateString();
        $friday = Carbon::parse($monday)->addDays(4)->toDateString();


        $result = '';
        if($rowNo >=1 && $rowNo <=17){
            $result = Carbon::createFromFormat('Y-m-d g:ia',$monday.' '.$startTime);
        }
        if($rowNo >17 && $rowNo <=34){
            $result = Carbon::createFromFormat('Y-m-d g:ia',$tuesday.' '.$startTime);
        }
        if($rowNo > 34 && $rowNo <=51){
            $result = Carbon::createFromFormat('Y-m-d g:ia',$wednesday.' '.$startTime);
        }
        if($rowNo >51  && $rowNo <=68){
            $result = Carbon::createFromFormat('Y-m-d g:ia',$thursday.' '.$startTime);
        }
        if($rowNo >68 && $rowNo <=85){
            $result = Carbon::createFromFormat('Y-m-d g:ia',$friday.' '.$startTime);
        }
        if($resetRowNo > 1 ){
            $result = $result->addMinutes( ($interval*$resetRowNo)-$interval );
        }
        return $result;
    }
}