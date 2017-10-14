<?php

namespace Modules\Schedule\Http\Controllers\Admin;

use Carbon\Carbon;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Http\Controllers\Admin\AdminBaseController;
use Modules\Schedule\Entities\Activity;
use Modules\Schedule\Entities\Schedule;
use Modules\Schedule\Entities\ScheduleDate;
use Modules\Schedule\Entities\Teacher;
use Modules\Schedule\Events\Handlers\InsertTeacherExcelSchedule;
use Modules\Schedule\Events\ImportExcelSchedule;
use Modules\Schedule\Events\ReadEventSchedule;
use Modules\Schedule\Events\ReadTeacherExcelFile;
use Modules\Schedule\Http\Requests\UploadExcelRequest;
use Modules\Schedule\Repositories\ScheduleRepository;
use Modules\Schedule\Repositories\TeacherRepository;
use Modules\User\Contracts\Authentication;
use Modules\User\Permissions\PermissionManager;
use Modules\User\Repositories\RoleRepository;
use Modules\User\Repositories\UserRepository;
use Nexmo\Laravel\Facade\Nexmo;

class ScheduleController extends AdminBaseController
{
    use ValidatesRequests;
    /**
     * @var Authentication
     */
    private $auth;

    protected $teacherRepository;
    protected $scheduleRepository;

    /**
     * @param PermissionManager $permissions
     * @param UserRepository    $user
     * @param RoleRepository    $role
     * @param Authentication    $auth
     */
    public function __construct(
        Authentication $auth,
        TeacherRepository $teacherRepository,
        ScheduleRepository $scheduleRepository
    ) {
        parent::__construct();

        $this->auth = $auth;
        $this->teacherRepository = $teacherRepository;
        $this->scheduleRepository = $scheduleRepository;
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        $currentUser = $this->auth->user();
        $teachers = $this->teacherRepository->all();

        return view('schedule::admin.schedule.admin_schedule', compact('currentUser','teachers'));
    }

    public function getUpload(){
        return view('schedule::admin.schedule.upload', compact(''));
    }

    public function getSyncData(Request $request){
        dd($request->all());
        $current = $request->get("current")+1;
        $steps = 100;

        echo json_encode(array("step" => $current, "total" => $steps, "label" => "server completed ". $current . " of " . $steps));
    }

    public function doUpload(UploadExcelRequest $request){

        $interval = $request->get('interval');
        $startTime = $request->get('startTime');

        $file = $request->file('importedFile');
        $file->move(storage_path('imports'), 'import.' . $file->getClientOriginalExtension());

        $path = storage_path('imports')."/import.xlsx";

        DB::table('makeit__schedules')->delete();
        DB::table('makeit__schedules_event')->delete();
        DB::table('makeit__teachers')->delete();
        DB::table('makeit__schedule_dates')->delete();

        //SETUP STARTTIME
        $date = Carbon::now()->toDateString();
        $scheduleDate = Carbon::createFromFormat('Y-m-d g:ia',$date.' '.$startTime)->toDateTimeString();

        ScheduleDate::create([
           'date'=>$scheduleDate,
            'start_date'=>$scheduleDate,
            'day_name'=> Carbon::today()->format('D'),
            'interval'=> $interval
        ]);

        $this->_doImportSheetOld($path,$interval,$startTime);

        $this->_doImportSheetEvent($path,$interval,$startTime);


        $request->session()->flash('success','Upload excel file successfully');
        return redirect()->back();
    }

    private function _doImportSheetOld($path,$interval,$startTime){

        $objPHPExcel = \PHPExcel_IOFactory::load($path);

        //GET OLD SHEET
        $objWorksheet = $objPHPExcel->getSheet(0);
        $highestRow = $objWorksheet->getHighestRow();
        $highestRow = $highestRow -2;

        $mondayMergeRange = $objWorksheet->getCell('B1')->getMergeRange();
        $totalTimeSlotArray = $objWorksheet->rangeToArray($mondayMergeRange);
        $totalTimeSlot = count($totalTimeSlotArray[0]);

        //update old timeslots number
        $item = ScheduleDate::first();
        $item->old_total_timeslots = $totalTimeSlot;
        $item->save();

        for($rowNumber=1; $rowNumber<= $highestRow; $rowNumber++){
            event(new ReadTeacherExcelFile($rowNumber,$interval,$startTime));
        }
    }

    private function _doImportSheetEvent($path,$interval,$startTime){

        $objPHPExcel = \PHPExcel_IOFactory::load($path);

        //GET EVENT SHEET
        $objWorksheet = $objPHPExcel->getSheet(1);
        $highestRow = $objWorksheet->getHighestRow();
        $highestRow = $highestRow -2;

        $mondayMergeRange = $objWorksheet->getCell('B1')->getMergeRange();
        $totalTimeSlotArray = $objWorksheet->rangeToArray($mondayMergeRange);
        $totalTimeSlot = count($totalTimeSlotArray[0]);

        //update old timeslots number
        $item = ScheduleDate::first();
        $item->event_total_timeslots = $totalTimeSlot;
        $item->save();

        for($rowNumber=1; $rowNumber<= $highestRow; $rowNumber++){
            event(new ReadEventSchedule($rowNumber,$interval,$startTime));
        }
    }


    private function _importExcelSchedule($path,$limitRow,$limitRunRow){


        $objPHPExcel = \PHPExcel_IOFactory::load($path);
        $objWorksheet = $objPHPExcel->getActiveSheet();

        $startRow = ($limitRow * $limitRunRow) > $limitRow ? ($limitRow * $limitRunRow) - $limitRow +1 : 0;
        $endRow = $limitRow * $limitRunRow;

        $daysInWeek = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
        $hoursInDays = 17;

        $result = [];

        for ($row = $startRow; $row <= $endRow; ++$row) {
            Log::info('***start processing row ' . $row.'***');
            $teacherName = $objWorksheet->getCellByColumnAndRow(0 , $row)->getValue();
            $scheduleRow = [];
            if(!empty($teacherName)){
                $n=0;

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
//                            for($k=0; $k<=100 ; $k++){
//                                if($objWorksheet->getCellByColumnAndRow($j-$k , $row)->getValue() != null){
//                                    $scheduleRow[$daysInWeek[$n]][$j] = $objWorksheet->getCellByColumnAndRow($j-$k , $row)->getValue();
//                                    break;
//                                }
//                            }

                    }
                    if($j%$hoursInDays==0){
                        $n++;
                    }
                }
                foreach($scheduleRow as $day => $srow){

                    foreach($srow as $key => $value ){
                        if($value == null){
//                                unset($scheduleRow[$key]);
                        }
                        else{
                            $values = explode('\n',$value);
                            if(count($values) > 0){
                                for($v = 0; $v < count($values); $v++){
                                    $scheduleData = [
                                        'teacher_id'=>$teacherObject->id,
                                        'subject_code'=>$values[$v],
                                        'date_id'=> $key
                                    ];
                                    $this->scheduleRepository->create($scheduleData);
                                }
                            }
                            else{
                                $scheduleData = [
                                    'teacher_id'=>$teacherObject->id,
                                    'subject_code'=>$value,
                                    'date_id'=> $key
                                ];
                                $this->scheduleRepository->create($scheduleData);
                            }


                        }
                    }
                }
            }
            else{
                //teacher name blank
            }
            Log::info('***end processing row ' . $row.'***');
        }
    }
    private function _get_current_week()
    {
        // set current timestamp
        $today = time();
        $w = array();
        // calculate the number of days since Monday
                $dow = date('w', $today);
        $offset = $dow - 1;
        if ($offset < 0) {
            $offset = 6;
        }
        // calculate timestamp from Monday to Sunday
        $monday = $today - ($offset * 86400);
        $tuesday = $monday + (1 * 86400);
        $wednesday = $monday + (2 * 86400);
        $thursday = $monday + (3 * 86400);
        $friday = $monday + (4 * 86400);
        $saturday = $monday + (5 * 86400);
        $sunday = $monday + (6 * 86400);

        $format = 'Y-m-d';

        // return current week array
        $w['monday '] = Carbon::createFromTimestamp($monday)->format($format) ;
        $w['tuesday '] = Carbon::createFromTimestamp($tuesday)->format($format) ;
        $w['wednesday'] = Carbon::createFromTimestamp($wednesday)->format($format);
        $w['thursday '] = Carbon::createFromTimestamp($thursday)->format($format) ;
        $w['friday '] = Carbon::createFromTimestamp($friday)->format($format) ;
        $w['saturday '] = Carbon::createFromTimestamp($saturday)->format($format) ;
        $w['sunday '] = Carbon::createFromTimestamp($sunday)->format($format) ;
        ;
        return $w;
    }

    public function getUserByDate(Request $request){
        $date = $request->get('date');
        $date = Carbon::createFromFormat('m/d/Y',$date)->toDateString();
        $dayName = Carbon::parse($date);

//        DB::enableQueryLog();
//        $rows = $this->scheduleRepository->getByAttributes(['start_date'=>$date]);
        $query = Schedule::with('teacher');
        if($dayName->isMonday()){
            $query->where('day_name','Monday');
        }elseif($dayName->isTuesday()){
            $query->where('day_name','Tuesday');
        }elseif($dayName->isWednesday()){
            $query->where('day_name','Wednesday');
        }elseif($dayName->isThursday()){
            $query->where('day_name','Thursday');
        }elseif($dayName->isFriday()){
            $query->where('day_name','Friday');
        }
        else{
            $query->whereNull('day_name');
        }
        $query->orderBy('teacher_id','ASC')->groupBy('teacher_id');

        $rows = $query->get();
//        dd(DB::getQueryLog());
        $result = [];
        if(count($rows) > 0){
            foreach($rows as $row){
                $teacher = [
                  'id'=>$row->teacher ? $row->teacher->id:0,
                  'text'=>$row->teacher ? $row->teacher->name: '',
                ];
                array_push($result,$teacher);
            }
        }

        $assignedTeacher = Activity::where('selected_date',$dayName->toDateString())->get();
        if(count($assignedTeacher) > 0){
            foreach($assignedTeacher as $row){
                $teacher = [
                    'id'=>$row->replaced_teacher_id ? $row->replaced_teacher_id:0,
                    'text'=>$row->replaceTeacher ? $row->replaceTeacher->name: '',
                ];
                array_push($result,$teacher);
            }
        }

        //sort list result
        ksort($result);

        if(count($result) > 0){
            return response()->json(['result'=>$result,'status'=>1]);
        }
        else{
            return response()->json(['result'=>[],'status'=>0]);
        }

    }

    public function sendNotification(Request $request){

        //update schedule
        $schedules = $request->get('schedules');
        $replaceTeacherId = $request->get('replaceTeacher');
        $replaceDate = $request->get('replaceDate');
        if(is_array($schedules)){
            foreach($schedules as $scheduleId){
                $selectedSchedule = $this->scheduleRepository->find($scheduleId);

                //delete first
                Activity::where('teacher_id',$selectedSchedule->teacher_id)
                            ->where('schedule_id',$scheduleId)
                    ->where('selected_date',Carbon::parse($replaceDate)->toDateString())
                    ->delete();


                Activity::create([
                    'teacher_id'=>$selectedSchedule->teacher_id,
                    'replaced_teacher_id'=>$replaceTeacherId,
                    'schedule_id'=>$scheduleId,
                    'selected_date'=> Carbon::parse($replaceDate)->toDateString(),
                    'status'=> Activity::ASSIGNED_STATUS
                ]);


//                $selectedSchedule->teacher_id = $replaceTeacherId;
//                $selectedSchedule->save();
            }
        }

        $body = $request->get('msg_body');
        if($replaceTeacherId){
            $phoneNumber = env('DEFAULT_PHONENUMBER');
            $from = env('DEFAULT_PHONENUMBER');

//            Nexmo::message()->send([
//                'to' => $phoneNumber,
//                'from' => $from,
//                'text' => $body
//            ]);
            $request->session()->flash('success','Send SMS successfully');
        }
        else{
            $request->session()->flash('error','Send SMS error');
        };
        return redirect()->back();

    }

    public function getUserByEvent(Request $request){
        $event = $this->scheduleRepository->find($request->get('eventId'));
//        dd($request->get('eventId'));
        $user = $this->teacherRepository->find($event->teacher_id);

        $result = [
            'status'=>1,
            'result'=> [
                ['id'=>$user->id,'text'=>$user->name]
            ]
        ];
        return response()->json($result);
    }

    public function actionWorker(){
        $currentUser = $this->auth->user();
        $teacher = Teacher::get()->first();
        $teachers = $this->teacherRepository->all();

        return view('schedule::admin.schedule.worker', compact('currentUser','teachers','teacher'));
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

    public function getAvailableUserByEvents2(Request $request){
        $events = $request->get('eventIds');
        $date = $request->get('date');
        $optionRead = $request->get('optionAssigned');
        $dayName = Carbon::parse($date);

        $result['data']['time_data'] = [];
        $status = 0;

        if(count($events)>0){
            DB::enableQueryLog();
            $whereData = [];
            $subQuery = '';
            $slotIds = [];

            if(count($events) == 1){
                $slot = $this->scheduleRepository->find($events[0]);
                array_push($whereData,$slot->slot_id);
                array_push($slotIds,$slot->slot_id);

                $subQuery .= 's.slot_id=?';
                $whereData[]= $slot->day_name;
                $whereData[]= $slot->teacher_id;
            }elseif(count($events) > 1){
                foreach($events as $k => $event){
                    $slot = $this->scheduleRepository->find($event);
                    array_push($whereData,$slot->slot_id);
                    array_push($slotIds,$slot->slot_id);

                    if($k==0){
                        $subQuery .= 's.slot_id=? OR ';
                    }
                    else{
                        if($k  == count($events) -1){
                            $subQuery .= 's.slot_id=?';

                            $whereData[]= $slot->day_name;
                            $whereData[]= $slot->teacher_id;
                        }else{
                            $subQuery .= 's.slot_id=? OR ';
                        }
                    }

                }
            }

//            DB::enableQueryLog();
            $userTimelines = DB::select('SELECT t.name,t.id as teacher_id  FROM 
	 ( SELECT * FROM makeit__teachers t WHERE NOT EXISTS( SELECT * FROM makeit__schedules s WHERE t.id = s.teacher_id AND ( '.$subQuery.') AND s.day_name=? ) ) t	
WHERE t.id != ?',$whereData);
//            dd(DB::getQueryLog());

//            $userTimelines = $query->groupBy('teacher_id')->get();
            if(!empty($userTimelines)){
//                $collection = collect($userTimelines);
//                $userTimelines = $collection->groupBy('name')->toArray();

                $status = 1;
                $i = 0;

                $query = Activity::where('teacher_id',$slot->teacher_id)
                    ->where('selected_date',$dayName->toDateString());
                if(count($events) > 1){
                    foreach($events as $scheduleId){
                        $query->where('schedule_id',$scheduleId);
                    }
                }
                else{
                    $query->where('schedule_id',$events[0]);
                }

                $assignedSchedules= $query->get();
                $collectionSchedule = collect($assignedSchedules)->map(function($schedule){
                    return $schedule->replaced_teacher_id;
                })->toArray();

                foreach($userTimelines as $key => $items) {
                    $teacherName = $items->name;
                    $teacherId = $items->teacher_id;

                    $schedulesByTeacher = Schedule::where('teacher_id',$teacherId)->where('day_name',$dayName->format('l'))->get();
//                    dd(DB::getQueryLog());
//                    dd($schedulesByTeacher);
                    if(count($schedulesByTeacher) > 0){
                        foreach($schedulesByTeacher as $item){
                            $result['data']['time_data'][$i]['required']['classes'][] = [
                                'slot'=>[$item->slot_id],
                                'lesson'=>str_replace('/',',',trim(preg_replace('/\r\n|\r|\n/', ',', $item->subject_code)))
                            ];
                        }
                    }
                    else{
                        $result['data']['time_data'][$i]['required']['classes'] =[];
                    }

                    $result['data']['time_data'][$i]['required']['teacher'] = $teacherName;
                    $result['data']['time_data'][$i]['required']['teacher_id'] = $teacherId;
                    $result['data']['time_data'][$i]['required']['status'] = '';
                    $result['data']['time_data'][$i]['required']['number'] = '';
                    if(in_array($teacherId, $collectionSchedule)){
                        $result['data']['time_data'][$i]['required']['content'] = 'Assigned';
                    }
                    else{
                        $result['data']['time_data'][$i]['required']['content'] = '';
                    }


                    $i++;

                }
            }
            else{

            }
        }
        else{
            //empty events
        }
        return response()->json(['result'=>$result,'status'=>$status]);
    }

    public function getFreeListTeacherWithAssigned(Request $request){
        $events = $request->get('eventIds');
        $date = $request->get('date');
        $optionRead = $request->get('optionAssigned');
        $dayName = Carbon::parse($date);

        $result['data']['time_data'] = [];
        $status = 0;

        if(count($events)>0){
            foreach($events as $eventId){

            }

        }
        else{
            //empty events
        }
        return response()->json(['result'=>$result,'status'=>$status]);
    }


    public function getUserTimeline(Request $request){
        $teacherId = $request->get('teacher_id');
        $teacher = $this->teacherRepository->find($teacherId);

        $dayName = Carbon::parse($request->get('date'));

        $query = Schedule::where('teacher_id',$teacherId);

        $dayNameData = '';
        if($dayName->isMonday()){
            $query->where('day_name','Monday');
            $dayNameData = 'Monday';
        }elseif($dayName->isTuesday()){
            $query->where('day_name','Tuesday');
            $dayNameData = 'Tuesday';
        }elseif($dayName->isWednesday()){
            $query->where('day_name','Wednesday');
            $dayNameData = 'Wednesday';
        }elseif($dayName->isThursday()){
            $query->where('day_name','Thursday');
            $dayNameData = 'Thursday';
        }elseif($dayName->isFriday()){
            $query->where('day_name','Friday');
            $dayNameData = 'Friday';
        }else{
            $query->whereNull('day_name');
            $dayNameData = '';
        }

        $query->groupBy('date_id');
        $rows= $query->get();


        $result = [];
        $group[] = [
            'id'=>$teacherId,
            'content'=> $teacher->name.'&nbsp;&nbsp;&nbsp;',
            'value'=>$teacherId
        ];

        //GET OLD TIME SLOT
        $timeData = $this->scheduleRepository->getOldTimeSlot($request->get('date'));
        $result['data']['time_slot'] = $timeData;


        $result['data']['time_data'][0] = [];
        $result['data']['time_data'][0]['required']['teacher'] = $teacher->name;
        $classes = $result['data']['time_data'][0]['required']['classes'] = [];

//        $result['data']['time_data'][0]['paired'] =[];
        $pairs = [];
        $beAssigned = [];

        $assignedSchedules = Activity::where('teacher_id',$teacher->id)
            ->where('selected_date',$dayName->toDateString())
            ->get();
        $collectionSchedule = collect($assignedSchedules)->map(function($schedule){
            return $schedule->schedule_id;
        })->toArray();

        $beAssignedSchedules = Activity::where('replaced_teacher_id',$teacher->id)
            ->where('selected_date',$dayName->toDateString())
            ->get();
        $collectionBeAssignedSchedule = collect($assignedSchedules)->map(function($schedule){
            return $schedule->schedule_id;
        })->toArray();

        if($rows){
            foreach($rows as $row){
                $data = [
                    'id'=>$row->id,
                    'class' => $row->class_name,
                    'lesson'=> str_replace('\n','/',$row->subject_code),
                    'slot'=> [$row->slot_id],
                    'start'=> substr($row->start_time,0,-3),
                    'end'=> substr($row->end_time,0,-3),
                    'status'=>'unavaliable',
                    'content'=>'relif made',
                    'number'=> '99'
                ];
                if(in_array($row->id, $collectionSchedule) ){
                    array_push($pairs,$data);
//                    array_push($classes,$data);//need custom script js
                }
                else{
                    array_push($classes,$data);
                }

            }
            if(count($beAssignedSchedules) > 0){
                foreach($beAssignedSchedules as $beAssignedSchedule){
                    $data = [
                        'id'=>$beAssignedSchedule->schedule->id,
                        'class' => $beAssignedSchedule->schedule->class_name,
                        'lesson'=> str_replace('\n','/',$beAssignedSchedule->schedule->subject_code),
                        'slot'=> [$beAssignedSchedule->schedule->slot_id],
                        'start'=> substr($beAssignedSchedule->schedule->start_time,0,-3),
                        'end'=> substr($beAssignedSchedule->schedule->end_time,0,-3),
                        'status'=>'unavaliable',
                        'content'=>'relif made',
                        'number'=> '99'
                    ];
                    array_push($beAssigned,$data);
//                    array_push($classes,$data);
                }
            }
            $result['data']['time_data'][0]['required']['classes'] = $classes;
            $result['data']['time_data'][0]['required']['paired'] = $pairs;
            $result['data']['time_data'][0]['required']["substituted"] = [];
            $result['data']['time_data'][0]['required']['red'] = $beAssigned;

            return response()->json(['result'=>$result,'status'=>1]);
        }else{
            return response()->json(['result'=>$result,'status'=>0]);
        }

    }
    public function getAvailableUser(Request $request){
        $event = $request->get('eventId');

        $eventItem = $this->scheduleRepository->find($event);
        $timeSlotId = $eventItem->date_id;
        $startDate = $eventItem->start_date;

//        $availableUser = Teacher::whereHas('schedule', function($q) use ($startDate){
//            $q->whereDate('start_date','!=',$startDate);
//        })->get()->toArray();


        $teachers = Teacher::where('id','!=',$eventItem->teacher_id)->get()->toArray();
        $availableTeachers = $teachers;
        $busyTeachers = Schedule::where('teacher_id','!=',$eventItem->teacher_id)
//            ->whereBetween('start_date',[$eventItem->start_date,$eventItem->end_date])
            ->whereDate('start_date',$eventItem->start_date)
            ->where('date_id',$timeSlotId)
            ->groupBy('teacher_id')->get();

        if(count($busyTeachers) > 0){
            foreach($busyTeachers as $teacher){
                $teacherId = $teacher->teacher_id;
                for($i = 0; $i < count($teachers); $i++){
                    if($teachers[$i]['id'] == $teacherId){
                        unset($availableTeachers[$i]);
                    }
                }
            }
            $availableTeachers = array_values($availableTeachers);
        }



        $users = [];
        $timelines = [];
        $expand = [];
        if(count($availableTeachers) > 0){
            $i=0;
            foreach($availableTeachers as $user){
                $row = [
                    'id'=>$user['id'],
                    'content'=>$user['name'],
                    'value'=>$user['id'],
                ];
                array_push($users,$row);
                $i++;
            }
        }

        if(count($users)>0){
            $date = Carbon::parse($eventItem->start_date)->format('Y-m-d');
            foreach ($users as $key=> $user){
                //get timelines foreach user
                DB::enableQueryLog();
                $userTimelines = Schedule::where('teacher_id',$user['id'])
                    ->where('date_id','>',$timeSlotId)
                    ->whereDate('start_date',$date)->get();

                if($userTimelines){
                    foreach($userTimelines as $k => $uTimeline) {

                        if($k==0){
                            $item = [
                                'id' => $eventItem->id.'_'.uniqid(),
                                'group' => $user['id'],
                                'content' => '',
                                'start' => Carbon::parse($eventItem->start_date)->format('Y-m-d\TH:i:s'),
                                'end' => Carbon::parse($eventItem->end_date)->format('Y-m-d\TH:i:s'),
                                'className'=> 'orange',
                            ];
                            array_push($timelines, $item);
                        }
                        $item = [
                            'id' => $uTimeline->id,
                            'group' => $user['id'],
                            'content' => $uTimeline->subject_code,
                            'start' => Carbon::parse($uTimeline->start_date)->format('Y-m-d\TH:i:s'),
                            'end' => Carbon::parse($uTimeline->end_date)->format('Y-m-d\TH:i:s'),
                        ];

                        array_push($timelines, $item);
                    }
                }
            }
        }


        $min = Carbon::parse($eventItem->start_date)->hour(0)->minute(0)->second(0)->format('Y-m-d\TH:i:s');
        $max = Carbon::parse($eventItem->end_date)->hour(23)->minute(59)->second(59)->format('Y-m-d\TH:i:s');

        $result = [
            'users' => $users,
            'timelines'=>$timelines
        ];
        return response()->json(['result'=>$result,'status'=>1,'event'=>$eventItem,'min'=>$min,'max'=>$max]);
    }


    public function getAssignFormModal(Request $request){

        $teacher_id = $request->get('teacher_id');
        $teacher = $this->teacherRepository->find($teacher_id)->first();
        $scheduleIds = $request->get('schedule_ids');
        $selectedDate = Carbon::parse($request->get('selected_date'))->toDateString();

        if(count($scheduleIds) > 0){
            $schedules = Schedule::whereIn('id',$scheduleIds)->get();
        }

        return view('schedule::admin.schedule.assign_form_modal',compact('teacher','schedules','selectedDate'));
    }

    public function cancelReplaceTeacher(Request $request){

        $selectedDate = Carbon::parse($request->get('selectedDate'))->toDateString();
        Activity::where('schedule_id',$request->get('scheduleid'))->where('selected_date',$selectedDate)->delete();

        $request->session()->flash('success','Cancel replace teacher successfully');

        return redirect()->to('backend/schedule');
    }
}
