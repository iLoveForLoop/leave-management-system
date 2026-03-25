<?php

namespace App\Http\Controllers;
use App\Models\Holiday;
use App\Models\YearlyHoliday;
use App\Services\YearlyHolidayService;
use App\Models\OvertimeRequest;
use App\Models\HRSupervisor;
use App\Models\Leave;
use App\Models\User;
use App\Models\TimeManagement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDF;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Requests\EmailUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function indexLMS(Request $request) {
        $month = $request->query('month', now()->month);
        $today = now()->toDateString();

        $birthdays = User::whereMonth('birthday', $month)
        ->orderByRaw('DAY(birthday) ASC')
        ->get();

        $teamLeaves = Leave::whereMonth('start_date', $month)
                            ->where('status', 'approved')
                            ->where('end_date', '>=', $today)
                            ->with('user')
                            ->get();


        $monthPadded = str_pad($month, 2, '0', STR_PAD_LEFT);
        $year = now()->year;

        $overtimeRequests = OvertimeRequest::where('status', 'approved')
            ->where('inclusive_dates', 'LIKE', "%-{$monthPadded}-%")
            ->where('inclusive_dates', 'LIKE', "{$year}-%")
            ->get();

        return view('employee.dashboard', compact('teamLeaves', 'birthdays', 'month', 'overtimeRequests'));
    }

    public function leaderboard()
    {
        $employees = User::with(['leaves' => function ($query) {
            $query->where('status', 'approved')
                  ->whereMonth('start_date', now()->month)
                  ->whereYear('start_date', now()->year);
        }])->get();

        $employees->each(function ($employee) {
            $employee->total_absences = $employee->leaves->sum(function ($leave) {
                return \Carbon\Carbon::parse($leave->start_date)
                        ->diffInDays(\Carbon\Carbon::parse($leave->end_date)) + 1;
            });
        });
        $employees = $employees->sortBy('total_absences')->take(10)->values();
        return view('employee.leaderboard', compact('employees'));
    }


    public function showUsersModal()
    {
        $employees = User::with(['leaves' => function ($query) {
            $query->where('status', 'approved')
                  ->whereMonth('start_date', now()->month)
                  ->whereYear('start_date', now()->year);
        }])->get();

        $employees->each(function ($employee) {
            $employee->total_absences = $employee->leaves->sum(function ($leave) {
                return \Carbon\Carbon::parse($leave->start_date)
                        ->diffInDays(\Carbon\Carbon::parse($leave->end_date)) + 1;
            });
        });

        $employees = $employees
                        ->sortBy(['total_absences', 'last_name'])
                        ->values();

        return view('employee.partials.users-modal', compact('employees'));
    }


    public function loginLmsCto() {
        return view('main_resources.logins.lms_cto_login');
    }

    public function makeRequest()
    {
        $leaves = Leave::where('user_id', Auth::id())->latest()->get();
        $gender = Auth::user()->gender;
        return view('employee.make_request', compact('leaves', 'gender'));
    }


    private function isDocumentRequired(Request  $request){
        if($request->start_date < now() && $request->days_applied > 5){
            return true;
        }

        if($request->start_date > now()){
            return true;
        }

        if($request->days_applied > 5){
            return true;
        }

        return false;
    }

    public function store(Request $request, YearlyHolidayService $yearlyHolidayService)
{

    $leaveValidationRules = [];
    $isViolatesPriorDays = false;

    switch ($request->leave_type) {
        case 'Vacation Leave':
        case 'Special Privilege Leave':
            $leaveValidationRules = [
                'within_philippines' => 'required_without:abroad_details|string|nullable',
                'abroad_details' => 'required_without:within_philippines|string|nullable',
            ];
            break;

        case 'Sick Leave':
            $leaveValidationRules = [
                'in_hospital_details' => 'required_without:out_patient_details|string|nullable',
                'out_patient_details' => 'required_without:in_hospital_details|string|nullable',
                'leave_files' => $this->isDocumentRequired($request) ? 'required|array' : 'array',
                'leave_files.*' => 'file|mimes:pdf,jpg,jpeg,png|max:1048'

            ];
            break;

        case 'Study Leave':
            $leaveValidationRules = [
                'completion_masters' => 'required_without:bar_review|boolean|nullable',
                'bar_review' => 'required_without:completion_masters|boolean|nullable',
            ];
            break;

        case 'Other Purposes':
            $leaveValidationRules = [
                'monetization' => 'required_without:terminal_leave|boolean|nullable',
                'terminal_leave' => 'required_without:monetization|boolean|nullable',
            ];
            break;
        case 'Wellness Leave':

            $leaveValidationRules = [
                'days_applied' => 'required|integer|max:3',
            ];

            if ($request->wellness_leave_type === 'sick') {
                $leaveValidationRules = array_merge($leaveValidationRules, [
                    'in_hospital_details' => 'required_without:out_patient_details|string|nullable',
                    'out_patient_details' => 'required_without:in_hospital_details|string|nullable',
                    'leave_files' => $this->isDocumentRequired($request) ? 'required|array' : 'array',
                    'leave_files.*' => 'file|mimes:pdf,jpg,jpeg,png|max:1048',
                ]);
            }

            break;

        case 'Others':
            $leaveValidationRules = [
                'others_details' => 'required|string|nullable'
            ];
            break;
    }

    $advanceFilingRules = [
        'Vacation Leave' => 5,
        'Special Privilege Leave' => 7,
        'Solo Parent Leave' => 5,
        'Special Leave Benefits for Women Leave' => 5,
        'Sick Leave' => 0,
        'Maternity Leave' => 0,
        'Paternity Leave' => 0,
        'Mandatory Leave' => 0,
    ];

    $inclusiveLeaveTypes = [
        'Maternity Leave',
        'Study Leave',
        'Rehabilitation Privilege',
        'Special Leave Benefits for Women Leave'
    ];

    $isViolatesPriorDays = false;
    $request->validate(array_merge([
        'leave_type' => 'required|string',
        'start_date' => [
            'required',
            'date',
            function ($attribute, $value, $fail) use ($request, $advanceFilingRules, &$isViolatesPriorDays) {
                $leaveType = $request->leave_type;
                $startDate = Carbon::parse($value);
                $today = Carbon::now();
                $advanceDaysRequired = $advanceFilingRules[$leaveType] ?? 0;

                if ($advanceDaysRequired > 0) {
                    $minStartDate = $today->copy()->addDays($advanceDaysRequired);

                    if ($startDate->lt($minStartDate)) {
                        $isViolatesPriorDays = true;
                    }
                }
            }
        ],
        'end_date' => 'required|date|after_or_equal:start_date',
        'reason' => 'nullable|string',
        'leave_files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        'days_applied' => 'required|integer|min:1',
        'signature' => auth()->user()->signature_path ? 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120' : 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        'commutation' => 'required|boolean',
        'leave_details' => 'nullable|array',
        'abroad_details' => 'nullable|string',
    ], $leaveValidationRules));



    $user = Auth::user();
    $startDate = Carbon::parse($request->start_date);
    $endDate = Carbon::parse($request->end_date);
    $daysBetween = $endDate->diffInDays(now());



    $requiredDocs = [
        'Maternity Leave' => 'Proof of Pregnancy (Ultrasound, Doctor’s Certificate)',
        'Paternity Leave' => 'Proof of Child Delivery (Birth Certificate, Medical Certificate, Marriage Contract)'
    ];

    $requiresDocs = in_array($request->leave_type, ['Maternity Leave', 'Paternity Leave']);

    if ($requiresDocs && !$request->hasFile('leave_files')) {
        return redirect()->back()->withErrors([
            'leave_files' => "For {$request->leave_type}, please upload the required documents: " . $requiredDocs[$request->leave_type]
        ]);
    }



    if (in_array($request->leave_type, $inclusiveLeaveTypes)) {
        $daysApplied = $startDate->diffInDays($endDate) + 1;

        // ADDING 0.25 TO SOME LEAVE APPLICATIONS
        if (in_array($request->leave_type, ['Sick Leave', 'Vacation Leave', 'Mandatory Leave'])){
            $temp = $daysApplied * 0.25;
            $daysApplied = $temp + $daysApplied;
        }
        //-----------------------------------



    } else {
        $daysApplied = 0;
        $currentDate = $startDate->copy();
        $holidays = $yearlyHolidayService->getHolidaysBetweenDates($startDate, $endDate);

        while ($currentDate->lte($endDate)) {
            if (!$currentDate->isWeekend() && !in_array($currentDate->format('Y-m-d'), $holidays)) {
                $daysApplied++;
            }
            $currentDate->addDay();
        }

        if ($daysApplied === 0) {
            $isValidStartDate = !$startDate->isWeekend() && !$yearlyHolidayService->isHoliday($startDate);

            if ($isValidStartDate) {
                $daysApplied = 1;
                // ADDING 0.25 TO SOME LEAVE APPLICATIONS
                if (in_array($request->leave_type, ['Sick Leave', 'Vacation Leave', 'Mandatory Leave'])){
                    $temp = $daysApplied * 0.25;
                    $daysApplied = $temp + $daysApplied;
                }
                //-----------------------------------
            } else {
                return redirect()->back()->withErrors([
                    'start_date' => 'Your selected dates only include weekends/holidays which are not counted for this leave type.'
                ]);
            }
        }

        // ADDING 0.25 TO SOME LEAVE APPLICATIONS
        if (in_array($request->leave_type, ['Sick Leave', 'Vacation Leave', 'Mandatory Leave'])){
            $temp = $daysApplied * 0.25;
            $daysApplied = $temp + $daysApplied;
        }
        //-----------------------------------
    }

    // $leaveTypeForBalance = $request->leave_type === 'Mandatory Leave' ? 'Vacation Leave' : $request->leave_type;
    $leaveTypeForBalance = $request->leave_type;


    if ($leaveTypeForBalance === 'Sick Leave') {
        $availableLeaveBalance = $user->sick_leave_balance;
    } elseif ($leaveTypeForBalance === 'Vacation Leave') {
        $availableLeaveBalance = $user->vacation_leave_balance;

    }else {
        $availableLeaveBalance = match ($leaveTypeForBalance) {
            'Special Privilege Leave' => $user->special_privilege_leave,
            'Maternity Leave' => $user->maternity_leave,
            'Paternity Leave' => $user->paternity_leave,
            'Solo Parent Leave' => $user->solo_parent_leave,
            'Study Leave' => $user->study_leave,
            '10-Day VAWC Leave' => $user->vawc_leave,
            'Rehabilitation Privilege' => $user->rehabilitation_leave,
            'Special Leave Benefits for Women Leave' => $user->special_leave_benefit,
            'Special Emergency Leave' => $user->special_emergency_leave,
            'Wellness Leave' => $user->wellness_leave_balance,
            'Mandatory Leave' => $user->mandatory_leave_balance,
            default => 0,
        };
    }


    if($availableLeaveBalance < $daysApplied){

        return redirect()->back()->withErrors(['You do not have enough Leave balance for this request.']);
    }

    if (in_array($leaveTypeForBalance, ['Sick Leave', 'Vacation Leave'])) {
        $combinedBalance = $user->sick_leave_balance + $user->vacation_leave_balance;
        if ($daysApplied > $combinedBalance) {
            return redirect()->back()->withErrors(['end_date' => 'You do not have enough combined Sick and Vacation Leave balance for this request.']);
        }
    } else {
        if ($daysApplied > $availableLeaveBalance) {
            return redirect()->back()->withErrors(['end_date' => 'You do not have enough balance for ' . $request->leave_type . '.']);
        }
    }

    $leaveFiles = [];
    if ($request->hasFile('leave_files')) {
        foreach ($request->file('leave_files') as $file) {
            $path = $file->store('leave_files', 'public');
            $leaveFiles[] = $path;
        }
    }

    $leaveDetails = [];



    if ($request->leave_type === 'Vacation Leave') {

        if ($request->filled('within_philippines')) {
            $leaveDetails['Within the Philippines'] = $request->within_philippines;
        }
        if ($request->filled('abroad_details')) {
            $leaveDetails['Abroad'] = $request->abroad_details;
        }

        // Deduct the VL Balance

        $user->vacation_leave_balance -= $daysApplied;
        $user->save();

        // $current_vl_balance  = $user->vacation_leave_balance;


    }

    if ($request->leave_type === 'Mandatory Leave') {

        if($user->vacation_leave_balance < $daysApplied){
            return redirect()->back()->withErrors(['You do not have enough Leave balance for this request.']);
        }

        $user->vacation_leave_balance -= $daysApplied;
        $user->mandatory_leave_balance -= $daysApplied;
        $user->save();

    }



    if ( $request->leave_type === 'Special Privilege Leave') {

        if ($request->filled('within_philippines')) {
            $leaveDetails['Within the Philippines'] = $request->within_philippines;
        }
        if ($request->filled('abroad_details')) {
            $leaveDetails['Abroad'] = $request->abroad_details;
        }

        // Deduct the VL Balance

        $user->special_privilege_leave -= $daysApplied;
        $user->save();

        // $current_vl_balance  = $user->vacation_leave_balance;


    }



    if ($request->leave_type === 'Sick Leave') {
        if ($request->has('in_hospital')) {
            $leaveDetails['In Hospital'] = $request->input('in_hospital_details', 'Yes');
        }
        if ($request->has('out_patient')) {
            $leaveDetails['Out Patient'] = $request->input('out_patient_details', 'Yes');
        }

         // Deduct the SL Balance
        $user->sick_leave_balance = $user->sick_leave_balance - $daysApplied;
        $user->save();

    }

    // WELLNESS
    if($request->leave_type === 'Wellness Leave'){

        if($request->wellness_leave_type === 'vacation'){
            if ($request->filled('within_philippines')) {
            $leaveDetails['Within the Philippines'] = $request->within_philippines;
            }
            if ($request->filled('abroad_details')) {
                $leaveDetails['Abroad'] = $request->abroad_details;
            }
        }

        if($request->wellness_leave_type === 'sick'){
            if ($request->has('in_hospital')) {
            $leaveDetails['In Hospital'] = $request->input('in_hospital_details', 'Yes');
            }
            if ($request->has('out_patient')) {
                $leaveDetails['Out Patient'] = $request->input('out_patient_details', 'Yes');
            }
        }

        $user->wellness_leave_balance = $user->wellness_leave_balance - $daysApplied;
        $user->save();
    }

    if ($request->leave_type === 'Study Leave') {
        if ($request->has('completion_masters')) {
            $leaveDetails[] = 'Completion of Master\'s Degree';
        }
        if ($request->has('bar_review')) {
            $leaveDetails[] = 'BAR Review';
        }
    }

    if ($request->leave_type === 'Other Purposes') {
        if ($request->has('monetization')) {
            $leaveDetails[] = 'Monetization of Leave Credits';
        }
        if ($request->has('terminal_leave')) {
            $leaveDetails[] = 'Terminal Leave';
        }
    }

    if ($request->leave_type === 'Others') {
        if ($request->filled('others_details')) {
            $leaveDetails[] = 'Other Details';
            $leaveDetails[] = $request->others_details;
        }
    }


    // if ($request->leave_type === 'Mandatory Leave') {
    //     $currentYear = Carbon::now()->year;

    //     $mandatoryLeaveUsed = Leave::where('user_id', $user->id)
    //         ->where('leave_type', 'Mandatory Leave')
    //         ->whereYear('start_date', $currentYear)
    //         ->whereIn('status', ['approved'])
    //         ->sum('days_applied');

    //     $remainingMandatoryLeave = 5 - $mandatoryLeaveUsed;

    //     if ($daysApplied > $remainingMandatoryLeave) {
    //         return redirect()->back()->withErrors(['end_date' => 'You have exceeded the 5-day Mandatory Leave for the year.']);
    //     }


    //     $user->vacation_leave_balance = $user->vacation_leave_balance - $daysApplied;
    //     $user->save();

    // }



    $signaturePath = auth()->user()->signature_path;



        if ($request->hasFile('signature')) {



            $signatureFile = $request->file('signature');
            $filename = time() . '_' . $signatureFile->getClientOriginalName();


            $signaturePath = $signatureFile->storeAs(
                'signatures',
                $filename,
                'public'
            );


            auth()->user()->update([
                'signature_path' => $signaturePath
            ]);

        }

    if($request->leave_type == 'Sick Leave'){
            $before_sick_leave_balance = $user->sick_leave_balance +  $daysApplied;
            $before_vacation_balance = $user->vacation_leave_balance;
    } else if($request->leave_type == 'Vacation Leave' || $request->leave_type == 'Mandatory Leave'){
            $before_vacation_balance = $user->vacation_leave_balance +  $daysApplied;
            $before_sick_leave_balance = $user->sick_leave_balance;
    } else{
        $before_sick_leave_balance = $user->sick_leave_balance;
        $before_vacation_balance = $user->vacation_leave_balance;
    }

    // if($request->leave_type == 'Vacation Leave' || $request->leave_type == 'Mandatory Leave'){
    //         $before_vacation_balance = $user->vacation_leave_balance +  $daysApplied;
    // }else{
    //         $before_sick_leave_balance = $user->sick_leave_balance +  $daysApplied;
    // }

    // $before_vacation_balance = $user->vacation_leave_balance +  $daysApplied;





    // Create Leave
    $leave = Leave::create([
        'user_id' => auth()->id(),
        'leave_type' => $request->leave_type,
        'leave_details' => json_encode($leaveDetails),
        'start_date' => $request->start_date,
        // 'vacation_balance_before' => auth()->user()->vacation_leave_balance,
        'vacation_balance_before' => $before_vacation_balance,
        // 'sick_balance_before' => auth()->user()->sick_leave_balance,
        'sick_balance_before' => $before_sick_leave_balance,
        'end_date' => $request->end_date,
        'salary_file' => $request->salary_file,
        'days_applied' => $daysApplied,
        'commutation' => $request->commutation,
        'date_filing' => now(),
        'reason' => $request->reason,
        'signature' => $signaturePath,
        'leave_files' => json_encode($leaveFiles),
        'status' => 'pending',
    ]);


    if($isViolatesPriorDays){
        $leave->violations()->create([
            'user_id' => auth()->id()
        ]);
    }

    if($request->leave_type === 'Sick Leave' && $daysBetween >= 7 && $startDate < now()){
        $leave->violations()->create([
            'user_id' => auth()->id(),
            'violation_type' => 'sick_leave'
        ]);
    }



    notify()->success('Leave request submitted successfully! It is now pending approval.');
    return redirect()->back();
}


public function cancel($id)
{
    $leave = Leave::findOrFail($id);
    $user = Auth::user();


    // if ($leave->status === 'approved' && $leave->hr_status === 'approved') {
    //     $this->restoreLeaveBalance($user, $leave);
    // }

    $this->restoreLeaveBalance($user, $leave);

    $leave->status = 'cancelled';
    $leave->save();

    notify()->success('Leave request has been cancelled and balance restored.');
    return redirect()->back();
}

    public function cancelCTO($id)
    {
        $cto = OvertimeRequest::findOrFail($id);
        $user = Auth::user();

        if ($cto->status === 'cancelled') {
            notify()->warning('CTO request is already cancelled.');
            return redirect()->back();
        }

        if ($cto->status === 'approved' && $cto->hr_status === 'approved' && $cto->supervisor_status === 'approved') {
            $user->increment('overtime_balance', $cto->working_hours_applied);
        }

        $this->restoreNewestCocLog($user, $cto->working_hours_applied);
        $user->overtime_balance += $cto->working_hours_applied;
        $user->save();

        $cto->status = 'cancelled';
        $cto->save();

        notify()->success('CTO request has been cancelled and balance restored.');
        return redirect()->back();
    }

    private function restoreNewestCocLog($user, int $totalHours): void
    {
        $cocLogs = $user->cocLogs()
            ->where('consumed', '>', 0)
            ->orderBy('expires_at', 'desc') // NEWEST first
            ->lockForUpdate()
            ->get();

        foreach ($cocLogs as $cocLog) {
            if ($totalHours <= 0) {
                break;
            }

            $restorable = $cocLog->consumed;

            if ($restorable <= 0) {
                continue;
            }

            if ($restorable >= $totalHours) {
                // Can fully restore here
                $cocLog->consumed -= $totalHours;
                $cocLog->coc_earned += $totalHours;
                $totalHours = 0;
            } else {
                // Restore everything and continue
                $cocLog->coc_earned += $restorable;
                $cocLog->consumed = 0;
                $totalHours -= $restorable;
            }

            $cocLog->save();
        }

        if ($totalHours > 0) {
            throw new \Exception('Unable to fully restore COC balance.');
        }
    }


public function restore($id)
{
    $leave = Leave::findOrFail($id);
    $user = Auth::user();

    if ($leave->status === 'cancelled' && $leave->hr_status === 'approved') {
        $this->deductLeaveBalance($user, $leave);
        $leave->status = 'approved';
    } else {
        $leave->status = 'pending';
    }

    if($leave->leave_type === "Vacation Leave" || $leave->leave_type === "Mandatory Leave" ){
            if($user->vacation_leave_balance < $leave->days_applied){
            return redirect()->back()->with('error', 'Not enough balance.');
        }
        $user->vacation_leave_balance -= $leave->days_applied;
    }

    else if($leave->leave_type === "Special Privilege Leave"){
        if($user->special_privilege_leave < $leave->days_applied){

                return redirect()->back()->with('error', 'Not enough balance.');
            }
        $user->special_privilege_leave -= $leave->days_applied;
    }


    else if  ($leave->leave_type === "Sick Leave") {

            if($user->sick_leave_balance < $leave->days_applied){

                notify()->warning('Not enough balance.');
                return redirect()->back();
            }

            $user->sick_leave_balance -= $leave->days_applied;
    }


    else if($leave->leave_type === "Wellness Leave") {

            if($user->wellness_leave_balance < $leave->days_applied){
                notify()->warning('Not enough balance.');
                return redirect()->back();
            }

            $user->wellness_leave_balance -= $leave->days_applied;
    }

    $user->save();

    $leave->save();

    notify()->success('Leave request has been restored and balance deducted.');
    return redirect()->back();
}

public function restoreCTO($id)
{
    $CTO = OvertimeRequest::findOrFail($id);
    $user = Auth::user();

    if ($CTO->status !== 'cancelled') {
        notify()->warning('This CTO request is not cancelled and cannot be restored.');
        return redirect()->back();
    }

    if ($CTO->status === 'cancelled' && $CTO->hr_status === 'approved') {
        $user->decrement('overtime_balance', $CTO->working_hours_applied);
        $CTO->status = 'approved';
    } else {
        $CTO->status = 'pending';
    }

    if($CTO->working_hours_applied > $user->overtime_balance){
        notify()->warning('Your CTO Balance is not enough.');
        return redirect()->back();
    }

    $this->deductOldestCocLog($user, $CTO->working_hours_applied);
    $user->overtime_balance -= $CTO->working_hours_applied;
    $user->save();

    $CTO->save();
    notify()->success('CTO request has been restored and balance deducted.');

    return redirect()->back();
}


private function deductOldestCocLog($user, int $totalHours): void
    {
        $cocLogs = $user->cocLogs()
            ->where('is_expired', false)
            ->where('coc_earned', '>', 0)
            ->orderBy('expires_at', 'asc')
            ->lockForUpdate()
            ->get();

        foreach ($cocLogs as $cocLog) {
            if ($totalHours <= 0) {
                break;
            }

            $available = $cocLog->coc_earned;

            if ($available <= 0) {
                continue;
            }

            if ($available >= $totalHours) {
                // Enough balance in this log
                $cocLog->coc_earned -= $totalHours;
                $cocLog->consumed += $totalHours;
                $totalHours = 0;
            } else {
                // Not enough → consume everything
                $cocLog->consumed += $available;
                $cocLog->coc_earned = 0;
                $totalHours -= $available;
            }

            $cocLog->save();
        }

        if ($totalHours > 0) {
            throw new \Exception('Not enough COC balance to deduct.');
        }
    }

private function restoreLeaveBalance($user, $leave)
{
    $days = $leave->days_applied;

    switch ($leave->leave_type) {
        case 'Vacation Leave':
            $user->vacation_leave_balance += $days;
            break;

        case 'Mandatory Leave':
            $user->vacation_leave_balance += $days;
            $user->mandatory_leave_balance += $days;
            break;

        case 'Sick Leave':
            $user->sick_leave_balance += $days;
            break;
        case 'Wellness Leave':
            $user->wellness_leave_balance += $days;
            break;

        case 'Maternity Leave':
            $user->maternity_leave += $days;
            break;

        case 'Paternity Leave':
            $user->paternity_leave += $days;
            break;

        case 'Solo Parent Leave':
            $user->solo_parent_leave += $days;
            break;

        case 'Study Leave':
            $user->study_leave += $days;
            break;

        case 'VAWC Leave':
            $user->vawc_leave += $days;
            break;

        case 'Rehabilitation Leave':
            $user->rehabilitation_leave += $days;
            break;

        case 'Special Leave Benefit':
            $user->special_leave_benefit += $days;
            break;

        case 'Special Privilege Leave':
            $user->special_privilege_leave += $days;
            break;

        case 'Special Emergency Leave':
            $user->special_emergency_leave += $days;
            break;
    }

    $user->save();
}

private function deductLeaveBalance($user, $leave)
{
    $days = $leave->days_applied;

    switch ($leave->leave_type) {
        case 'Vacation Leave':

            if ($user->vacation_leave_balance >= $days) {
                $user->vacation_leave_balance -= $days;
            } elseif (($user->vacation_leave_balance + $user->sick_leave_balance) >= $days) {
                $remainingDays = $days;

                if ($user->vacation_leave_balance > 0) {
                    $deductFromVacation = min($remainingDays, $user->vacation_leave_balance);
                    $user->vacation_leave_balance -= $deductFromVacation;
                    $remainingDays -= $deductFromVacation;
                }

                if ($remainingDays > 0) {
                    $user->sick_leave_balance -= $remainingDays;
                }
            } else {
                throw ValidationException::withMessages(['error' => 'Insufficient combined Sick and Vacation Leave balance.']);
            }
            break;

        case 'Mandatory Leave':

            if ($user->vacation_leave_balance >= $days) {
                $user->vacation_leave_balance -= $days;
                $user->mandatory_leave_balance -= $days;
            } elseif (($user->vacation_leave_balance + $user->sick_leave_balance) >= $days) {
                $remainingDays = $days;

                if ($user->vacation_leave_balance > 0) {
                    $deductFromVacation = min($remainingDays, $user->vacation_leave_balance);
                    $user->vacation_leave_balance -= $deductFromVacation;
                    $remainingDays -= $deductFromVacation;
                }

                if ($remainingDays > 0) {
                    $user->sick_leave_balance -= $remainingDays;
                }


                $user->mandatory_leave_balance -= $days;
            } else {
                throw ValidationException::withMessages(['error' => 'Insufficient combined Sick and Vacation Leave balance.']);
            }
            break;

        case 'Sick Leave':
            if ($user->sick_leave_balance >= $days) {
                $user->sick_leave_balance -= $days;
            } elseif (($user->sick_leave_balance + $user->vacation_leave_balance) >= $days) {
                $combinedBalance = $user->sick_leave_balance + $user->vacation_leave_balance;

                if ($combinedBalance >= $days) {
                    $remainingDays = $days;

                    if ($user->sick_leave_balance > 0) {
                        $deductFromSick = min($remainingDays, $user->sick_leave_balance);
                        $user->sick_leave_balance -= $deductFromSick;
                        $remainingDays -= $deductFromSick;
                    }

                    if ($remainingDays > 0) {
                        $user->vacation_leave_balance -= $remainingDays;
                    }
                }
            } else {
                throw ValidationException::withMessages(['error' => 'Insufficient combined Sick and Vacation Leave balance.']);
            }
            break;

        case 'Maternity Leave':
            if ($user->maternity_leave >= $days) {
                $user->maternity_leave -= $days;
            } else {
                throw ValidationException::withMessages(['error' => 'Insufficient Maternity Leave balance.']);
            }
            break;

        case 'Paternity Leave':
            if ($user->paternity_leave >= $days) {
                $user->paternity_leave -= $days;
            } else {
                throw ValidationException::withMessages(['error' => 'Insufficient Paternity Leave balance.']);
            }
            break;

        case 'Solo Parent Leave':
            if ($user->solo_parent_leave >= $days) {
                $user->solo_parent_leave -= $days;
            } else {
                throw ValidationException::withMessages(['error' => 'Insufficient Solo Parent Leave balance.']);
            }
            break;

        case 'Study Leave':
            if ($user->study_leave >= $days) {
                $user->study_leave -= $days;
            } else {
                throw ValidationException::withMessages(['error' => 'Insufficient Study Leave balance.']);
            }
            break;

        case 'VAWC Leave':
            if ($user->vawc_leave >= $days) {
                $user->vawc_leave -= $days;
            } else {
                throw ValidationException::withMessages(['error' => 'Insufficient VAWC Leave balance.']);
            }
            break;

        case 'Rehabilitation Leave':
            if ($user->rehabilitation_leave >= $days) {
                $user->rehabilitation_leave -= $days;
            } else {
                throw ValidationException::withMessages(['error' => 'Insufficient Rehabilitation Leave balance.']);
            }
            break;

        case 'Special Leave Benefit':
            if ($user->special_leave_benefit >= $days) {
                $user->special_leave_benefit -= $days;
            } else {
                throw ValidationException::withMessages(['error' => 'Insufficient Special Leave Benefit balance.']);
            }
            break;

        case 'Special Emergency Leave':
            if ($user->special_emergency_leave >= $days) {
                $user->special_emergency_leave -= $days;
            } else {
                throw ValidationException::withMessages(['error' => 'Insufficient Special Emergency Leave balance.']);
            }
            break;
    }

    $user->save();
}


    public function showRequests() {
        $user = auth()->user();

        if (!$user) {
            return redirect()->route('login')->with('error', 'Please log in to view your reservations.');
        }

        $leaves = $user->leaves()->orderBy('created_at', 'desc')->paginate(10);

        return view('employee.leave_request', compact('leaves',));
    }

    public function show($id) {
        $leave = Leave::findOrFail($id);

        return view('employee.leave_show', compact('leave'));
    }

    public function profile() {
        $user = Auth::user();
        $gender = $user->gender;
        $currentYear = date('Y');


        $usedMandatoryLeaveDays = Leave::where('user_id', $user->id)
            ->where('leave_type', 'Mandatory Leave')
            ->whereYear('start_date', $currentYear)
            ->where('status', 'approved')
            ->sum('days_applied');

        $mandatoryBalance = $user->mandatory_leave_balance;
        $mandatoryLeaveDays = 5;
        $remainingLeaveDays = $mandatoryLeaveDays - $usedMandatoryLeaveDays;

        return view('employee.profile.index', compact('user', 'usedMandatoryLeaveDays', 'remainingLeaveDays', 'mandatoryBalance', 'gender'));
    }


    public function profile_edit(Request $request): View
    {
        return view('employee.profile.partials.update-profile-information-form', [
            'user' => $request->user(),
        ]);
    }
    public function password_edit(Request $request): View
    {
        return view('employee.profile.partials.update-password-form', [
            'user' => $request->user(),
        ]);
    }

    public function updateProfile(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        notify()->success('Profile Updated Successfully!');

        return Redirect::route('employee.profile.partials.update-profile-information-form')->with('status', 'profile-updated');
    }


    public function updateEmail(EmailUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        notify()->success('Email Updated Successfully!');

        return Redirect::route('employee.profile.partials.update-profile-information-form')->with('status', 'email-updated');
    }

    public function getLeaves(Request $request)
    {
        $month = $request->month ?? date('m');

        $leaves = Leave::with('user:id,name,first_name,last_name,profile_image')
            ->whereMonth('start_date', $month)
            ->orderBy('start_date', 'asc')
            ->get();

        return response()->json($leaves->map(function ($leave) {
            return [
                "id" => $leave->id,
                "first_name" => $leave->user->first_name,
                "last_name" => $leave->user->last_name,
                "start" => \Carbon\Carbon::parse($leave->start_date)->format('F j, Y'),
                "end" => \Carbon\Carbon::parse($leave->end_date)->format('F j, Y'),
                "status" => ucfirst($leave->status),
                "duration" => \Carbon\Carbon::parse($leave->start_date)->diffInDays($leave->end_date) + 1,
                "profile_image" => $leave->user->profile_image ? asset('storage/profile_images/' . $leave->user->profile_image) : asset('images/default.png')
            ];
        }));
    }

    public function getOvertimes(Request $request)
    {
        $month = $request->month ?? date('m');

        $overtimes = OvertimeRequest::with('user:id,name,first_name,last_name,profile_image')
            ->where('inclusive_dates', 'LIKE', '%-'.str_pad($month, 2, '0', STR_PAD_LEFT).'-%')
            ->orderByRaw("SUBSTRING_INDEX(inclusive_dates, ',', 1) ASC")
            ->get();

        return response()->json($overtimes->map(function ($overtime) {
            $dates = explode(', ', $overtime->inclusive_dates);
            $firstDate = \Carbon\Carbon::parse($dates[0]);
            $lastDate = \Carbon\Carbon::parse(end($dates));

            $dateDisplay = count($dates) === 1
                ? $firstDate->format('F j, Y')
                : $firstDate->format('F j, Y') . ' to ' . $lastDate->format('F j, Y');

            return [
                "id" => $overtime->id,
                "first_name" => $overtime->user?->first_name ?? 'Unknown',
                "last_name" => $overtime->user?->last_name ?? '',
                "date" => $dateDisplay,
                "admin_status" => ucfirst($overtime->admin_status ?? 'Pending'),
                "hours" => $overtime->working_hours_applied ?? 0,
                "profile_image" => $overtime->user?->profile_image
                    ? asset('storage/profile_images/' . $overtime->user->profile_image)
                    : asset('images/default.png'),
                "all_dates" => array_map(fn($d) => \Carbon\Carbon::parse($d)->format('F j, Y'), $dates)
            ];
        }));
    }

    public function updateProfileImage(Request $request)
    {
        $request->validate([
            'profile_image' => 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
        ]);

        $user = Auth::user();

        if ($request->hasFile('profile_image')) {
            if ($user->profile_image) {
                Storage::delete('public/profile_pictures/' . $user->profile_image);
            }

            $imagePath = $request->file('profile_image')->store('profile_images', 'public');
            $filename = basename($imagePath);


            $user->update(['profile_image' => $filename]);
        }

        return back()->with('success', 'Profile image updated successfully!');
    }

    public function viewPdf($id)
    {
        $leave = Leave::findOrFail($id);
        $officials = HRSupervisor::all();

        $supervisor = User::where('role', 'supervisor')->first();
        $hr = User::where('role', 'hr')->first();

        $pdf = PDF::loadView('pdf.leave_details', compact('leave', 'supervisor', 'hr', 'officials'));

        return $pdf->stream('leave_request_' . $leave->id . '.pdf');
    }


    public function calendar(Request $request)
    {
        $selectedYear = (int) $request->input('year', date('Y'));

        $holidays = YearlyHoliday::whereYear('date', $selectedYear)
            ->orWhere('repeats_annually', true)
            ->orderBy('date')
            ->get()
            ->map(function ($holiday) use ($selectedYear) {
                if ($holiday->repeats_annually) {
                    $date = Carbon::parse($holiday->date);

                    $holiday->date = Carbon::create((int) $selectedYear, (int) $date->month, (int) $date->day)->format('Y-m-d');
                }
                return $holiday;
            });

        $groupedHolidays = $holidays->groupBy(function ($item) {
            return Carbon::parse($item->date)->format('F Y');
        });

        $calendarData = $this->prepareCalendarData($holidays, $selectedYear);

        return view('employee.holiday-calendar', compact(
            'groupedHolidays',
            'calendarData',
            'selectedYear',
        ));
    }

    protected function prepareCalendarData($holidays, $year)
{
    $months = [];

    for ($month = 1; $month <= 12; $month++) {
        $year = (int) $year;
        $month = (int) $month;

        $date = Carbon::create($year, $month, 1);
        $daysInMonth = $date->daysInMonth;

        $monthData = [
            'name' => $date->format('F'),
            'year' => $year,
            'days' => []
        ];

        $monthHolidays = $holidays->filter(function ($holiday) use ($month) {
            return (int) Carbon::parse($holiday->date)->month === $month;
        });

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $currentDate = Carbon::create($year, $month, $day);

            $dayHolidays = $monthHolidays->filter(function ($holiday) use ($day) {
                return (int) Carbon::parse($holiday->date)->day === $day;
            });

            $monthData['days'][$day] = [
                'date' => $currentDate,
                'holidays' => $dayHolidays,
                'isWeekend' => $currentDate->isWeekend()
            ];
        }

        $months[$month] = $monthData;
    }

    return $months;
}

    public function editLeave($id) {
        $leave = Leave::findOrFail($id);
        return view('employee.edit', compact('id', 'leave'));
    }

    public function updateLeave(Request $request, $id, YearlyHolidayService $yearlyHolidayService)
{
    $leave = Leave::findOrFail($id);

    $today = Carbon::now();

    $startDate = Carbon::parse($request->start_date);
    $endDate = Carbon::parse($request->end_date);
    $days_applied = $startDate->diffInDays($endDate) + 1;

    $leaveValidationRules = [];

    switch ($request->leave_type) {
        case 'Vacation Leave':
        case 'Special Privilege Leave':
            $leaveValidationRules = [
                'within_philippines' => 'required_without:abroad_details|string|nullable',
                'abroad_details' => 'required_without:within_philippines|string|nullable',
            ];
            break;

        case 'Sick Leave':
            $leaveValidationRules = [
                'in_hospital_details' => 'required_without:out_patient_details|string|nullable',
                'out_patient_details' => 'required_without:in_hospital_details|string|nullable',
            ];
            break;

        case 'Study Leave':
            $leaveValidationRules = [
                'completion_masters' => 'required_without:bar_review|boolean|nullable',
                'bar_review' => 'required_without:completion_masters|boolean|nullable',
            ];
            break;

        case 'Other Purposes':
            $leaveValidationRules = [
                'monetization' => 'required_without:terminal_leave|boolean|nullable',
                'terminal_leave' => 'required_without:monetization|boolean|nullable',
            ];
            break;

        case 'Others':
            $leaveValidationRules = [
                'others_details' => 'required|string|nullable'
            ];
            break;
    }

    $advanceFilingRules = [
        'Vacation Leave' => 5,
        'Special Privilege Leave' => 7,
        'Solo Parent Leave' => 5,
        'Special Leave Benefits for Women Leave' => 5,
        'Sick Leave' => 0,
        'Maternity Leave' => 0,
        'Paternity Leave' => 0,
        'Mandatory Leave' => 0,
    ];

    $inclusiveLeaveTypes = [
        'Maternity Leave',
        'Study Leave',
        'Rehabilitation Privilege',
        'Special Leave Benefits for Women Leave'
    ];

    $request->validate(array_merge([
        'leave_type' => 'required|string',
        'start_date' => [
            'required',
            'date',
            function ($attribute, $value, $fail) use ($request, $advanceFilingRules) {
                $leaveType = $request->leave_type;
                $startDate = Carbon::parse($value);
                $today = Carbon::now();
                $advanceDaysRequired = $advanceFilingRules[$leaveType] ?? 0;

                if ($advanceDaysRequired > 0) {
                    $minStartDate = $today->copy()->addDays($advanceDaysRequired);

                    if ($startDate->lt($minStartDate)) {
                        $fail("You must request {$leaveType} at least {$advanceDaysRequired} days in advance.");
                    }
                }
            }
        ],
        'end_date' => 'required|date|after_or_equal:start_date',
        'reason' => 'nullable|string',
        'leave_files.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048', // Multiple files
        'days_applied' => 'required|integer|min:1',
        'commutation' => 'required|boolean',
        'leave_details' => 'nullable|array',
        'abroad_details' => 'nullable|string',
    ], $leaveValidationRules));

    $user = Auth::user();

    $leaveFiles = [];
    if ($request->hasFile('leave_files')) {
        foreach ($request->file('leave_files') as $file) {
            $path = $file->store('leave_files', 'public');
            $leaveFiles[] = $path;
        }
    }

    $leaveDetails = [];

    $leave->update([
        'leave_type' => $request->leave_type,
        'leave_details' => json_encode($leaveDetails),
        'start_date' => $request->start_date,
        'end_date' => $request->end_date,
        'salary_file' => $request->salary_file,
        'days_applied' => $days_applied,
        'commutation' => $request->commutation,
        'reason' => $request->reason,
        'signature' => $request->signature,
        'leave_files' => json_encode($leaveFiles),
        'status' => 'pending',
    ]);

    notify()->success('Leave request updated successfully!');
    return redirect()->back()->with('success', 'Leave request updated successfully.');
}

    public function deleteLeave($id) {
        Leave::findOrFail($id)->delete();
        return redirect()->back()->with('success', 'Leave request deleted successfully.');
    }

    public function markAsRead()
    {
        $user = auth()->user();

        if ($user) {
            $user->unreadNotifications->markAsRead();
        }

        return response()->json(['success' => true, 'message' => 'Notifications marked as read.']);
    }

    public function delete($id)
    {
        $user = Auth::user();

        if ($user) {
            $notification = $user->notifications()->find($id);
            if ($notification) {
                $notification->delete();
                return response()->json(['success' => true, 'message' => 'Notification deleted.']);
            }
        }

        return response()->json(['success' => false, 'message' => 'Notification not found.']);
    }

    public function deleteAll()
    {
        $user = Auth::user();

        if ($user) {
            $user->notifications()->delete();
            return response()->json(['success' => true, 'message' => 'All notifications deleted.']);
        }

        return response()->json(['success' => false, 'message' => 'No notifications found.']);
    }
}
