Dear {{ $employee->name }},

We are pleased to invite you to join us for a Maxicare Benefit Orientation:

<b>Speaker</b>: Ms. Jasmine Acosta (Maxicare)<br />
<b>Date</b>: July 2, 2025<br />
<b>Time</b>: 10:00 AM (Philippine Time)<br />
<b>Meeting Link</b>: https://zoom.us/my-meeting-link<br />
<b>Meeting ID</b>: 123 456 789<br />
<b>Passcode</b>: maxicare2025<br />

During this session, we will cover:<br />
- Overview of your healthcare benefits<br />
- How to use your Maxicare card<br />
- Available healthcare facilities<br />
- Claims procedures<br />
- Q&A session<br />

@if(count($dependents) > 0)
Your enrolled dependents:<br />
@foreach($dependents as $dependent)
- {!! $dependent->name !!} ({!! $dependent->relation !!})<br />
@endforeach
@endif

Please ensure to join the meeting at least 5 minutes before the scheduled time.