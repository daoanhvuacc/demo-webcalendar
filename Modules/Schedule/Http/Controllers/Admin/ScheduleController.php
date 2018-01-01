<?php

namespace Modules\Schedule\Http\Controllers\Admin;

use Carbon\Carbon;
use GuzzleHttp\Client;
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
use Modules\Schedule\Entities\Assignment;
use Modules\Schedule\Entities\Schedule;
use Modules\Schedule\Entities\ScheduleDate;
use Modules\Schedule\Entities\Teacher;
use Modules\Schedule\Events\Handlers\InsertTeacherExcelSchedule;
use Modules\Schedule\Events\ImportExcelSchedule;
use Modules\Schedule\Events\ReadEventSchedule;
use Modules\Schedule\Events\ReadSubjectSheet;
use Modules\Schedule\Events\ReadTeacherExcelFile;
use Modules\Schedule\Http\Requests\UploadExcelRequest;
use Modules\Schedule\Jobs\SendNotificationMail;
use Modules\Schedule\Repositories\AssignmentRepository;
use Modules\Schedule\Repositories\EventScheduleRepository;
use Modules\Schedule\Repositories\ScheduleRepository;
use Modules\Schedule\Repositories\TeacherRepository;
use Modules\User\Contracts\Authentication;
use Modules\User\Entities\Sentinel\User;
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
    protected $eventScheduleRepository;
    protected $assignmentRepository;
    protected $repository;

    /**
     * @param PermissionManager $permissions
     * @param UserRepository    $user
     * @param RoleRepository    $role
     * @param Authentication    $auth
     */
    public function __construct(
        Authentication $auth,
        TeacherRepository $teacherRepository,
        ScheduleRepository $scheduleRepository,
        EventScheduleRepository $eventScheduleRepository,
        AssignmentRepository $assignmentRepository
    ) {
        parent::__construct();

        $this->auth = $auth;
        $this->teacherRepository = $teacherRepository;
        $this->scheduleRepository = $scheduleRepository;
        $this->eventScheduleRepository = $eventScheduleRepository;
        $this->assignmentRepository = $assignmentRepository;
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

        $assignments = [];
        $assignmentList = Assignment::where('is_past',0)->get();
        if($assignmentList){
            $collection = collect($assignmentList);

            $assignmentArray = $collection->map(function ($item, $key) {
                return Carbon::parse($item->selected_date)->format('j/n/Y');
            });


            $assignments = $assignmentArray->all();
//            dd($assignmentArray->all());
        }

        return view('schedule::admin.schedule.admin_schedule', compact('currentUser','teachers','assignments'));
    }

    public function getUpload(){
        return view('schedule::admin.schedule.upload', compact(''));
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

        //remove all users
        User::where('id','!=',1)->delete();

        Assignment::whereNotNull('teacher_id')->update(['is_past'=>1]);

        //SETUP STARTTIME
        $date = Carbon::today()->toDateString();
        $scheduleDate = Carbon::createFromFormat('Y-m-d g:ia',$date.' '.$startTime)->toDateTimeString();
//        $scheduleDate = Carbon::createFromFormat('m/d/Y',$date)->toDateTimeString();

        ScheduleDate::create([
           'date'=>$scheduleDate,
//            'start_date'=>$scheduleDate,
            'day_name'=> Carbon::today()->format('D'),
            'interval'=> $interval
        ]);

        $this->_doImportSheetOld($path,$interval,$startTime);

        $this->_doImportSheetEvent($path,$interval,$startTime);

        $this->_doImportSheetSubject($path);


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
    private function _doImportSheetSubject($path){

        $objPHPExcel = \PHPExcel_IOFactory::load($path);

        //GET EVENT SHEET
        $objWorksheet = $objPHPExcel->getSheet(2);
        $highestRow = $objWorksheet->getHighestRow();
//        $highestRow = $highestRow-1;

        for($rowNumber=1; $rowNumber<= $highestRow; $rowNumber++){
            event(new ReadSubjectSheet($rowNumber));
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

    private function _getScheduleTable($selectedDate){
        if(!$selectedDate or empty($selectedDate) or !Carbon::parse($selectedDate) ){
            return false;
        }
        $selectedDate = Carbon::parse($selectedDate);

        $weekOfMonth = $selectedDate->weekOfMonth;
        $weekOfYear = $selectedDate->weekOfYear;

        $oddWeek = [1,2,5,6,9,10,12,13,16,17,20,21,26,27,30,31,34,35,37,38,41,42,45,46];
        $evenWeek = [3,4,7,8,11,14,15,18,19,28,29,32,33,39,40,43,44];

        if(in_array($weekOfYear,$oddWeek)){
            $tableName = 'old';
        }elseif(in_array($weekOfYear,$evenWeek)){
            $tableName = 'event';
        }else{
            $tableName = '';
        }
        return $tableName;

//        $uploadedDate = $this->_getDateUploadExcelFile();
//        $uploadedDate = Carbon::parse($uploadedDate);
//
//        $weekOfMonthUploaded = $uploadedDate->weekOfMonth;
//
//
//
//        $totalWeekOfMonth = $weekOfMonth + $weekOfMonthUploaded;
//        if($totalWeekOfMonth % 2 == 0){
//            $tableName = 'event';
//        }else{
//            $tableName = 'old';
//        }
//        return $tableName;
    }
    private function _getDateUploadExcelFile(){
        $item = ScheduleDate::first();
        return $item->start_date;
    }
    private function _getRepository($scheduleTable){
        if($scheduleTable == 'old'){
            $this->repository = $this->scheduleRepository;
        }else{
            $this->repository = $this->eventScheduleRepository;
        }
    }

    public function getUserByDate(Request $request){
        $date = $request->get('date');
        $date = Carbon::createFromFormat('m/d/Y',$date)->toDateString();
        $dayName = Carbon::parse($date);

        $scheduleTable = $this->_getScheduleTable($date);
        $this->_getRepository($scheduleTable);

        $assignmentList = Assignment::where('selected_date',$date)->where('is_past',0)->orderBy('teacher_name','ASC')->get();
        $collection = collect($assignmentList);

        $assignmentArray = $collection->map(function ($item, $key) {

            return [
                'id'=>$item->id,
                'teacher_name'=>$item->teacher_name,
                'replaced_teacher_name'=>$item->replaced_teacher_name,
                'lesson'=>$item->lesson,
                'start_time'=>substr(Carbon::parse($item->start_date)->toTimeString(),0,-3),
                'end_time'=>substr(Carbon::parse($item->end_date)->toTimeString(),0,-3),
                'schedule_type'=>$item->schedule_type,
                'start_date'=> Carbon::parse($item->start_date)->toDateTimeString(),
                'end_date'=> Carbon::parse($item->end_date)->toDateTimeString(),
            ];
        });
        $assignments = $assignmentArray->all();

        $users = $this->repository->getUsersByDate($date);
        if(count($users) > 0){
            return response()->json(['result'=>$users,'status'=>1,'schedule'=>$scheduleTable,'assignments'=>$assignments]);
        }
        else{
            return response()->json(['result'=>[],'status'=>0,'schedule'=>$scheduleTable]);
        }
    }

    public function getUserSchedules(Request $request){
        $userId = $request->get('teacher_id');
        $date = $request->get('date');

        $scheduleTable = $this->_getScheduleTable($date);
        $this->_getRepository($scheduleTable);

        $schedules = $this->repository->getUserSchedules($userId , $date);
        $termNumber = $this->assignmentRepository->getReliefNumber('term',$date,$userId);
        $schedules['data']['time_data'][0]['required']['term_done'] = $termNumber;

        return response()->json(['result'=>$schedules,'status'=>1]);
    }

    public function getFreeUsersWithSchedule(Request $request){
        $date = $request->get('date');
        $eventIds = $request->get('eventIds');
        $type = $request->get('type');

        $scheduleTable = $this->_getScheduleTable($date);
        $this->_getRepository($scheduleTable);

        $result = $this->repository->getFreeUserWithSchedules($date, $eventIds, $type);

        return response()->json(['result'=>$result,'status'=>1]);

    }

    public function sendNotification(Request $request){

        //update schedule
        $schedules = $request->get('schedules');
        $replaceTeacherId = $request->get('replaceTeacher');
        $replaceDate = $request->get('replaceDate');
        $body = $request->get('msg_body');
        $reason = $request->get('reason_absent');
        $additionalRemark = $request->get('addition_remark');

        $scheduleTable = $this->_getScheduleTable($replaceDate);
        $this->_getRepository($scheduleTable);

        $replaceStatus = $this->repository->replaceTeacher($schedules,$replaceTeacherId,$replaceDate,$reason,$additionalRemark);
        if($replaceStatus){
            $replaceTeacher = $this->teacherRepository->find($replaceTeacherId);
            $body .= "\n reply Yes|No ". $replaceStatus;

            if($request->has('send_sms')){
                if($replaceTeacher){
                    $phoneNumber = $replaceTeacher->phone_number;
                    $smsStatus = $this->_sendSMS($phoneNumber,$body);
                    if($smsStatus){
                        $request->session()->flash('success','Send SMS successfully');
                    }
                    else{
                        $request->session()->flash('error','Can not send SMS to teacher');
                    }
                }else{
                    $request->session()->flash('error','Can not send SMS to teacher');
                }
            }
            if($request->has('send_email')){
                if($replaceTeacher){
                    dispatch(new SendNotificationMail($replaceTeacher,$body));
                }else{
                    $request->session()->flash('error','Can not send Email to teacher');
                }
            }
        }
        else{
            $request->session()->flash('error','Can not relief teacher');
        };
        return redirect()->back();
    }

    public function sendAbsentRequest(Request $request){

        //update schedule
//        dd($request->all());
        $selectedDate = $request->get('selectedDate');
        $teacherId = $request->get('teacherId');
        $replaceTeacherId = $request->get('replaceTeacherId');
        $reason = $request->get('reason');
        $additionalRemark = $request->get('remark');
        $scheduleId = $request->get('input_scheduleId');

        $absentType = $request->get('absentType');
        if($absentType == 'fullDay'){
            $startDate = Carbon::today()->setTime(00,00,00)->toDateTimeString();
            $endDate = Carbon::today()->setTime(23,59,59)->toDateTimeString();
        }
        if($absentType == 'partialDay'){
            $startTime = explode(':',$request->get('input_startTime'));
            $endTime = explode(':',$request->get('input_endTime'));

            $startDate = Carbon::today()->setTime($startTime[0],$startTime[1],00)->toDateTimeString();
            $endDate = Carbon::today()->setTime($endTime[0],$endTime[1],59)->toDateTimeString();
        }
        if($absentType == 'prolonged'){
            $inputStartDate = $request->get('input_startDate');
            $inputEndDate = $request->get('input_endDate');

            $startDate = Carbon::parse($inputStartDate)->setTime(00,00,00)->toDateTimeString();
            $endDate = Carbon::parse($inputEndDate)->setTime(23,59,59)->toDateTimeString();
        }

        $body = $request->get('msg_body');

        $scheduleTable = $this->_getScheduleTable($selectedDate);
        $this->_getRepository($scheduleTable);

        $replaceStatus = $this->repository->createAbsentRequest($teacherId,$replaceTeacherId,$selectedDate,$reason,$additionalRemark,$startDate,$endDate,$absentType,$scheduleId);
        if($replaceStatus){
            $teacher = Teacher::find($teacherId);
            $replaceTeacher = Teacher::find($replaceTeacherId);

            $body = $teacher->name." just sent the absent request to ".$replaceTeacher->name."\n From: $startDate To: $endDate \n Reason: $reason";
            $body .= "\n reply Yes|No ". $replaceStatus;


            if($replaceTeacher){
                $phoneNumber = $replaceTeacher->phone_number;
                $smsStatus = $this->_sendSMS($phoneNumber,$body);
                if($smsStatus){
                    $request->session()->flash('success','Send SMS successfully');
                }
                else{
                    $request->session()->flash('error','Can not send SMS to teacher');
                }
            }else{
                $request->session()->flash('error','Can not send SMS to teacher');
            }

            dispatch(new SendNotificationMail($replaceTeacher,$body));
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

    public function actionWorker(Request $request){
        $currentUser = $this->auth->user();
        $teacher = Teacher::where('email',$currentUser->email)->get()->first();
        if(!$teacher){
            return redirect()->back()->with('error','Your account can not using this function');
        }
        $teachers = $this->teacherRepository->all();

        $date = '10/18/2017';
        $timeSlot = $this->scheduleRepository->getOldTimeSlot($date);

        return view('schedule::admin.schedule.worker', compact('currentUser','teachers','teacher','timeSlot'));
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



    public function getAssignFormModal(Request $request){

        $teacher_id = $request->get('teacher_id');
        $teacher = $this->teacherRepository->find($teacher_id)->first();
        $scheduleIds = $request->get('schedule_ids');
        $date = $request->get('selected_date');
        $selectedDate = Carbon::parse($request->get('selected_date'))->toDateString();
        $formatedDate = Carbon::parse($date)->toFormattedDateString();

        $scheduleTable = $this->_getScheduleTable($date);
        $this->_getRepository($scheduleTable);


        $numberAssignmentInSelectedDate = $this->assignmentRepository->getReliefNumber('date',$selectedDate,$teacher->id);
        $numberAssignmentInWeek = $this->assignmentRepository->getReliefNumber('week',$selectedDate,$teacher->id);
        $numberAssignmentInTerm = $this->assignmentRepository->getReliefNumber('term',$selectedDate,$teacher->id);
        $numberAssignmentInYear = $this->assignmentRepository->getReliefNumber('year',$selectedDate,$teacher->id);


        if(count($scheduleIds) > 0){
            $schedules = $this->repository->getSchedulesInArray($scheduleIds);
        }

        return view('schedule::admin.schedule.assign_form_modal',compact('teacher','schedules','selectedDate','formatedDate','numberAssignmentInSelectedDate','numberAssignmentInWeek','numberAssignmentInTerm','numberAssignmentInYear'));
    }

    public function cancelReplaceTeacher(Request $request){

        $date = $request->get('selectedDate');
        $selectedDate = Carbon::parse($date)->toDateString();
        $scheduleId = $request->get('scheduleid');

        $scheduleTable = $this->_getScheduleTable($date);
        $this->_getRepository($scheduleTable);

        $job = Assignment::where('schedule_id',$scheduleId)->where('selected_date',$selectedDate)->get()->first();
        if(!$job){
            $job = Assignment::where('schedule_event_id',$scheduleId)->where('selected_date',$selectedDate)->get()->first();
        }

        //new activity log
//        $activity = Activity::where('schedule_id',$request->get('scheduleid'))->where('selected_date',$selectedDate)->get()->first();
        $this->repository->userCancelAssignSchedule($scheduleId,$date);

        if($job){
            $body = 'Cancel Job: subject '.$job->subject .' with lesson '.$job->lesson .' \n '. $job->start_date .' \n '.$job->end_date;
            //send notify to sender
            $this->_sendSMS($job->teacher->phone_number,$body);
            //send notify to replace teacher
            $this->_sendSMS($job->replaceTeacher->phone_number,$body);

        }

        $request->session()->flash('success','Cancel replace teacher successfully');

        return redirect()->to('backend/schedule');
    }

    public function _sendSMS($toNumber,$body){
        $username = env('TAR_USERNAME');
        $pwd = env('TAR_PASSWORD');
        $tarNumber = '65'.$toNumber;
        $tarBody = urlencode($body);
        $messageId = Carbon::today()->timestamp;

        try {
            $client = new Client(); //GuzzleHttp\Client
//            $request = 'http://www.sendquickasp.com/client_api/index.php?username=yuhuasec&passwd=pass1234&tar_num=84986981718&tar_msg=Test&callerid=6584376346&route_to=api_send_sms';
            $request = 'http://www.sendquickasp.com/client_api/index.php?username='.$username.'&passwd='.$pwd.'&tar_num='.$tarNumber.'&tar_msg='.$tarBody.'&callerid=6584376346&route_to=api_send_sms';

            $sendSMSRequest = $client->get($request);
            $sendSMSResut = $sendSMSRequest->getBody()->getContents();
            if(strpos($sendSMSResut,'sent')){
                return true;
            }else{
                return false;
            }
        }catch (GuzzleException $error) {
            echo $error->getMessage();exit;
        }
    }

    public function testSMS(Request $request){
        $username = env('TAR_USERNAME');
        $pwd = env('TAR_PASSWORD');
        $tarNumber = '84986981718';
        $tarBody = 'Test';
        $messageId = Carbon::today()->timestamp;

        try {
            $client = new Client(); //GuzzleHttp\Client
//            $request = 'http://www.sendquickasp.com/client_api/index.php?username=yuhuasec&passwd=pass1234&tar_num=84986981718&tar_msg=Test&callerid=6584376346&route_to=api_send_sms';
            $request = 'http://www.sendquickasp.com/client_api/index.php?username='.$username.'&passwd='.$pwd.'&tar_num='.$tarNumber.'&tar_msg='.$tarBody.'&callerid=6584376346&route_to=api_send_sms';

            $sendSMSRequest = $client->get($request);
            $sendSMSResut = $sendSMSRequest->getBody()->getContents();
            if(strpos($sendSMSResut,'sent')){
                //sent
            }else{
                dd($sendSMSResut);
            }


        }catch (GuzzleException $error) {
            echo $error->getMessage();exit;
            return $this->respondInternalError();
        }
    }
}
