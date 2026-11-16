# DSMS Requirements for Development (from SRS v1.1, 10 Nov 2026)

Source of truth for the Driver Scheduling and Management System (DSMS) for the UIS Vehicle Section. Built with PHP, MySQL, HTML/CSS/JS on XAMPP.

## Actors and roles

- Staff: registers own account, submits/views/cancels vehicle requests.
- Head of Department (HoD): everything Staff can do, plus approve/reject requests from own department and cancel department requests.
- Driver: account created by Administrative Assistant; views assigned tasks, submits task reports, requests schedule changes, can submit requests.
- Administrative Assistant (AA): manages user accounts and vehicles, assigns drivers to requests/internal tasks/welfare duty, replaces drivers, views schedule and reports.
- Head of Admin (HoA): everything AA can do, plus approve/reject driver assignments, replacements, schedule changes, and Pay and Claim / Self Drive requests.

## Key business rules

- Login is not a use case; it is a precondition. Role-based access for all pages. Passwords hashed. Prepared statements for all queries. Session timeout 15 minutes. Lock login 15 minutes after 5 failed attempts.
- Nothing is deleted except internal tasks and welfare duty days. Users and vehicles are deactivated/activated.
- Vehicle reservation happens at submission (first-submitted, first-served). The requested quantity of the vehicle type is deducted for the period from departure time to return time plus 1 hour.
- Request lapses (status Lapsed) if HoD does not approve within 2 days; reservation released. Reminder email to HoD after 1 day. A scheduled job checks pending requests every hour.
- Reservation also released when a request is cancelled or rejected. Pay and Claim reserves no vehicle.
- Requests less than 2 days before departure are accepted and flagged Urgent. No paper form.
- Purpose types: Official and Unofficial (supporting letter required, PDF/JPG/PNG up to 5 MB). Lorry also has Personal Rental (no letter, RM3 per km round trip, goes AA then HoA, no HoD).
- Car: With Driver or Pay and Claim. Lorry: Self Drive only if the requester is a driver with a GDL licence.
- Approval flows: With Driver = HoD, then AA assigns, then HoA approves assignment. Pay and Claim / Self Drive = HoD then HoA. Personal Rental = AA (confirms distance, charge) then HoA. A HoD's own request goes to that HoD's head.
- Request statuses: Pending, Approved by Head of Department, Driver Assigned, Approved, Rejected, Cancelled, Lapsed, Completed. Every status change is stored in status history with user, time and reason.
- Every rejection needs a reason (Add Comment). Cancellation reason via pop-up form.
- Driver recommendation: keep active drivers with the licence class required by the vehicle (Class D car/van, GDL lorry, PSV bus); remove drivers with a clash or an approved schedule change; rank by weighted score of tasks this month (highest weight, request + internal + welfare), weekend tasks, years of experience. AA makes the final choice.
- Three task sources: request, internal task (Workshop, Refuelling, Test Drive), weekly welfare duty (24-hour, car or van only). Each has its own assign page and needs HoA approval.
- Driver task report updates vehicle current mileage, fuel level (Full, 3/4, 1/2, 1/4, Empty) and status (Active / Under Maintenance). Report cannot be deleted.
- Notifications: in-system and email for every status change.

## Use cases

### UC001 Manage User Account
- Actor: Administrative Assistant, Head of Admin
- Description: Allows the Administrative Assistant to register driver accounts and to view, update, deactivate and activate user accounts.
- Preconditions: The Administrative Assistant is logged in.
- Postconditions: The user account is registered, updated, deactivated or activated.
- Normal flow:
  1. Administrative Assistant selects User Account. -> System displays the list of users with their staff ID, name, department, role and status.
  2. Administrative Assistant selects Register Driver. -> System displays the driver registration form.
  3. Administrative Assistant enters the staff ID, name, UIS email, phone number, licence class, licence expiry date and start date of service, then selects Save. -> System checks that the staff ID and email are not registered. System creates the account with the Driver role and a default password. System sends the login details to the driver's UIS email.
- Alternative flows:
  - A1 View. At step 1, the Administrative Assistant searches by staff ID or name, or filters by role, then selects a user. The system displays the account details of the user.
  - A2 Update. The Administrative Assistant selects a user, then selects Edit. The system displays the account form. The Administrative Assistant updates the department, role, licence class, licence expiry date or start date of service, then selects Save. The system updates the account and sends an email to the user about the change.
  - A3 Deactivate. The Administrative Assistant selects an active user, then selects Deactivate and Confirm. The system deactivates the account and displays a success message.
  - A4 Activate. The Administrative Assistant selects an inactive user, then selects Activate. The system activates the account and displays a success message.
- Exceptions:
  - 3.1 The staff ID or email is already registered. The system displays a message that the account already exists.
  - 3.2 A required field is empty. The system displays a message to fill in all required fields.
  - A2.1 The Administrative Assistant sets a user as Head of Department for a department that already has one. The system displays a message that the department already has a Head of Department.
  - A3.1 The user is a driver with upcoming tasks. The system displays the tasks and asks the Administrative Assistant to replace the driver first (UC008).
  - A3.2 The user is a Head of Department with pending requests. The system displays a message that the pending requests must be handled first.
- Notes:
  - Drivers do not register themselves. Their accounts are registered by the Administrative Assistant.
  - Changing the default password is optional for the driver.
  - A user account is never deleted. A deactivated user cannot log in, but all records of the user remain in the system.

### UC002 Manage Vehicle
- Actor: Administrative Assistant, Head of Admin
- Description: Allows the Administrative Assistant to add vehicles and to view, update, deactivate and activate vehicle records.
- Preconditions: The Administrative Assistant is logged in.
- Postconditions: The vehicle is added, updated, deactivated or activated.
- Normal flow:
  1. Administrative Assistant selects Vehicle. -> System displays the list of vehicles with their plate number, vehicle type, seating capacity and status.
  2. Administrative Assistant selects Add Vehicle. -> System displays the add vehicle form.
  3. Administrative Assistant enters the plate number, vehicle type, brand, model, fuel type, seating capacity, required licence class, current mileage and current fuel level, then selects Save. -> System checks that the plate number is not registered. System adds the vehicle with the Active status and displays a success message.
- Alternative flows:
  - A1 View. At step 1, the Administrative Assistant filters the list by vehicle type or status, then selects a vehicle. The system displays the vehicle details, current mileage, current fuel level and upcoming bookings.
  - A2 Update. The Administrative Assistant selects a vehicle, then selects Edit. The system displays the current fuel level, current mileage and status. The Administrative Assistant updates them, then selects Save. The system updates the vehicle and displays a success message.
  - A3 Deactivate. The Administrative Assistant selects an active vehicle, then selects Deactivate and Confirm. The system deactivates the vehicle and displays a success message.
  - A4 Activate. The Administrative Assistant selects an inactive vehicle, then selects Activate. The system activates the vehicle and displays a success message.
- Exceptions:
  - 3.1 The plate number is already registered. The system displays a message that the vehicle already exists.
  - 3.2 A required field is empty. The system displays a message to fill in all required fields.
  - A2.1 The new mileage is lower than the current mileage. The system displays a message that the mileage is not valid.
  - A2.2 The status is changed to Under Maintenance or the vehicle is deactivated while it has upcoming bookings. The system displays the affected bookings and asks the Administrative Assistant to assign another vehicle to each booking.
- Notes:
  - The vehicle types are car, MPV, van, bus and lorry.
  - Only the current fuel level, current mileage and status can be updated. The status options are Active and Under Maintenance.
  - The fuel level options are Full, 3/4, 1/2, 1/4 and Empty.
  - The required licence class is used to filter drivers in Recommend Driver (UC009).
  - A vehicle is never deleted. A deactivated vehicle is not shown when a request is submitted.

### UC003 Assign Request Task
- Actor: Administrative Assistant, Head of Admin
- Description: Allows the Administrative Assistant to assign a vehicle and a driver to a request approved by the Head of Department. The system recommends suitable drivers.
- Preconditions: The Administrative Assistant is logged in. The request status is Approved by Head of Department.
- Postconditions: The vehicle and driver are assigned. The request is sent to the Head of Admin for approval.
- Normal flow:
  1. Administrative Assistant selects Assign Request Task. -> System displays the requests waiting for driver assignment, with Urgent requests at the top.
  2. Administrative Assistant selects a request. -> System displays the request details and the vehicles reserved for the request.
  3. Administrative Assistant selects the vehicle. -> System displays the recommended drivers for the vehicle (UC009).
  4. Administrative Assistant selects a driver for the outbound trip and the return trip, then selects Submit. -> System saves the assignment and changes the status to Driver Assigned. System sends the request to the Head of Admin for approval (UC026).
- Alternative flows:
  - A1 More than one vehicle. At step 3, the request needs more than one vehicle. The Administrative Assistant repeats steps 3 and 4 for each vehicle.
  - A2 Same driver for the return trip. At step 4, the Administrative Assistant selects Same Driver for Return Trip.
  - A3 Driver outside the recommendation. At step 4, the Administrative Assistant chooses a driver who is not in the recommended list. The system displays a warning with the driver's task count this month. The Administrative Assistant selects Confirm.
  - A4 Vendor bus. At step 2, not enough buses are available. The Administrative Assistant selects Vendor Bus and enters the vendor name and bus plate number. The system records the vendor bus for the request.
  - A5 Personal rental. At step 2, the request is a Personal Rental. The system displays the round-trip distance entered by the requester. The Administrative Assistant confirms or corrects the distance. The system calculates the charge at RM3 per kilometre.
- Exceptions:
  - 4.1 No driver is available for the period. The system displays a message that no driver is available.
- Relationships: <<include>> UC009 Recommend Driver
- Notes:
  - The Administrative Assistant selects the specific vehicle from the vehicles reserved when the request was submitted.
  - Pay and Claim and Self Drive requests are not assigned in this use case because no UIS driver is needed.

### UC004 Manage Internal Task
- Actor: Administrative Assistant, Head of Admin
- Description: Allows the Administrative Assistant to add, view, update and delete internal tasks such as workshop runs, refuelling and test drives.
- Preconditions: The Administrative Assistant is logged in.
- Postconditions: The internal task is added, updated or deleted.
- Normal flow:
  1. Administrative Assistant selects Internal Task. -> System displays the internal tasks with their date, task type, vehicle, driver and status.
  2. Administrative Assistant selects Add Task. -> System displays the internal task form.
  3. Administrative Assistant selects the task type, enters the date, start time, end time and description, selects the vehicle, then selects Save. -> System saves the task with the Waiting for Driver status. System reserves the vehicle for that period.
- Alternative flows:
  - A1 View. At step 1, the Administrative Assistant selects a task. The system displays the task details.
  - A2 Update. The Administrative Assistant selects a task, then selects Edit. The Administrative Assistant updates the details, then selects Save. The system updates the task and sends a notification to the assigned driver, if any.
  - A3 Delete. The Administrative Assistant selects a task, then selects Delete and Confirm. The system deletes the task, releases the vehicle reservation and sends a notification to the assigned driver, if any.
- Exceptions:
  - 3.1 A required field is empty. The system displays a message to fill in all required fields.
  - 3.2 The vehicle is not available for that period. The system displays a message that the vehicle is not available.
- Notes:
  - The task types are Workshop, Refuelling and Test Drive.
  - The driver is assigned through Assign Internal Task (UC005).

### UC005 Assign Internal Task
- Actor: Administrative Assistant, Head of Admin
- Description: Allows the Administrative Assistant to assign a driver to an internal task. The system recommends suitable drivers.
- Preconditions: The Administrative Assistant is logged in. The internal task status is Waiting for Driver.
- Postconditions: The driver is assigned. The task is sent to the Head of Admin for approval.
- Normal flow:
  1. Administrative Assistant selects Internal Task, then selects a task with the Waiting for Driver status. -> System displays the task details.
  2. Administrative Assistant selects Assign Driver. -> System displays the recommended drivers for the vehicle (UC009).
  3. Administrative Assistant selects a driver, then selects Submit. -> System saves the assignment and changes the status to Driver Assigned. System sends the task to the Head of Admin for approval (UC026).
- Alternative flows:
  - A1 Driver outside the recommendation. At step 3, the Administrative Assistant chooses a driver who is not in the recommended list. The system displays a warning with the driver's task count this month. The Administrative Assistant selects Confirm.
- Exceptions:
  - 3.1 No driver is available for the period. The system displays a message that no driver is available.
- Relationships: <<include>> UC009 Recommend Driver
- Notes:
  - Internal tasks are counted in each driver's task count for driver recommendation.

### UC006 Manage Welfare Duty
- Actor: Administrative Assistant, Head of Admin
- Description: Allows the Administrative Assistant to create, view, update and delete the weekly welfare duty schedule. Welfare duty is a 24-hour standby duty using a car or van.
- Preconditions: The Administrative Assistant is logged in.
- Postconditions: The weekly welfare duty schedule is created, updated or deleted.
- Normal flow:
  1. Administrative Assistant selects Welfare Duty. -> System displays the welfare duty schedule by week.
  2. Administrative Assistant selects Create Schedule, then selects the week. -> System displays the seven days of the week.
  3. Administrative Assistant selects the vehicle for each day, then selects Save. -> System saves the schedule with the Waiting for Driver status. System reserves the vehicle for each 24-hour duty.
- Alternative flows:
  - A1 View. At step 1, the Administrative Assistant selects a week. The system displays the vehicle and driver for each day of that week.
  - A2 Update. The Administrative Assistant selects a day, then selects Edit. The Administrative Assistant changes the vehicle, then selects Save. The system updates the duty and sends a notification to the assigned driver, if any.
  - A3 Delete. The Administrative Assistant selects a day, then selects Delete and Confirm. The system deletes the duty, releases the vehicle reservation and sends a notification to the assigned driver, if any.
- Exceptions:
  - 2.1 A schedule already exists for the selected week. The system displays a message that the schedule already exists.
  - 3.1 The vehicle is not available on that day. The system displays a message that the vehicle is not available.
- Notes:
  - Only cars and vans can be selected for welfare duty.
  - The drivers are assigned through Assign Welfare Duty Task (UC007).

### UC007 Assign Welfare Duty Task
- Actor: Administrative Assistant, Head of Admin
- Description: Allows the Administrative Assistant to assign a driver to each day of the weekly welfare duty schedule. The system recommends suitable drivers.
- Preconditions: The Administrative Assistant is logged in. The welfare duty schedule for the week exists.
- Postconditions: The drivers are assigned. The schedule is sent to the Head of Admin for approval.
- Normal flow:
  1. Administrative Assistant selects Welfare Duty, then selects a week with the Waiting for Driver status. -> System displays the seven days of the week.
  2. Administrative Assistant selects a day. -> System displays the recommended drivers for that day (UC009).
  3. Administrative Assistant selects a driver, repeats steps 2 and 3 for each day, then selects Submit. -> System saves the assignments and changes the status to Driver Assigned. System sends the schedule to the Head of Admin for approval (UC026).
- Alternative flows:
  - A1 Driver outside the recommendation. At step 3, the Administrative Assistant chooses a driver who is not in the recommended list. The system displays a warning with the driver's task count this month. The Administrative Assistant selects Confirm.
- Exceptions:
  - 3.1 Not every day has a driver when Submit is selected. The system displays a message that every day needs a driver.
  - 3.2 No driver is available on that day. The system displays a message that no driver is available.
- Relationships: <<include>> UC009 Recommend Driver
- Notes:
  - Welfare duty is counted in each driver's task count, so the duty is rotated among drivers.
  - Drivers with an approved schedule change on that day are not recommended.

### UC008 Replace Driver
- Actor: Administrative Assistant, Head of Admin
- Description: Allows the Administrative Assistant to replace the driver of an approved task when the driver cannot do the task. The reason for every replacement is recorded.
- Preconditions: The Administrative Assistant is logged in. The task status is Approved and the task has not started.
- Postconditions: The new driver is sent to the Head of Admin for approval. The replacement is recorded with the reason.
- Normal flow:
  1. Administrative Assistant selects Schedule, then selects a task. -> System displays the task details and the assigned driver.
  2. Administrative Assistant selects Replace Driver. -> System displays the recommended drivers for the vehicle (UC009) and a field for the reason.
  3. Administrative Assistant selects a new driver, enters the reason, then selects Confirm. -> System records the replacement with the reason. System sends the replacement to the Head of Admin for approval (UC026).
- Alternative flows:
  - A1 Replacement from another use case. At step 1, the replacement comes from an approved schedule change (UC027) or a driver account being deactivated (UC001). The system opens the affected task directly. The flow continues from step 2.
- Exceptions:
  - 3.1 The reason is empty. The system displays a message that the reason is required.
  - 3.2 No driver is available for the period. The system displays a message that no driver is available.
- Relationships: <<include>> UC009 Recommend Driver
- Notes:
  - Every replacement is recorded with the previous driver, new driver, reason, date and the user who made the change. These records are shown in View Report (UC011).
  - The replacement does not go to the Head of Department. Once the Head of Admin approves it, the system notifies the previous driver, the new driver and the requester.

### UC009 Recommend Driver
- Actor: Administrative Assistant, Head of Admin
- Description: Recommends suitable drivers for a task. The system filters drivers by licence class and availability, then ranks them with a weighted score.
- Preconditions: The Administrative Assistant is assigning or replacing a driver in UC003, UC005, UC007 or UC008.
- Postconditions: A ranked list of suitable drivers is displayed.
- Normal flow:
  1. Administrative Assistant opens the driver selection for a task. -> System keeps only active drivers who hold the licence class required for the vehicle. System removes drivers who already have a task during the task period or have an approved schedule change on that date. System counts each driver's tasks this month, including request tasks, internal tasks and welfare duty, and their weekend tasks. System calculates a weighted score for each driver from the number of tasks this month, the number of weekend tasks and years of experience. System displays the drivers ranked by score, with each driver's task counts.
- Exceptions:
  - 1.2 No driver passes the licence and availability checks. The system displays a message that no suitable driver is available.
- Notes:
  - This use case is included in UC003, UC005, UC007 and UC008.
  - The number of tasks this month has the highest weight, so drivers with fewer tasks are ranked higher.
  - The licence classes are Class D for cars and vans, GDL for lorries and PSV for buses.
  - The recommendation supports the decision. The Administrative Assistant makes the final choice.

### UC010 View Schedule
- Actor: Administrative Assistant, Head of Admin
- Description: Allows the Administrative Assistant and Head of Admin to view the schedule of all drivers and vehicles, including request tasks, internal tasks and welfare duty.
- Preconditions: The user is logged in.
- Postconditions: The schedule is displayed.
- Normal flow:
  1. Administrative Assistant selects Schedule. -> System displays the schedule of all drivers and vehicles for the current week, including request tasks, internal tasks and welfare duty.
  2. Administrative Assistant filters by date, driver or vehicle. -> System displays the matching schedule.
  3. Administrative Assistant selects a task. -> System displays the task details.
- Alternative flows:
  - A1 Monthly view. At step 1, the Administrative Assistant selects Month. The system displays the schedule for the whole month.
- Exceptions:
  - 2.1 No task matches the filter. The system displays a message that no record is found.
- Notes:
  - The schedule replaces the monthly driver schedule spreadsheet.
  - Drivers view their own tasks through View Assigned Task (UC023).

### UC011 View Report
- Actor: Administrative Assistant, Head of Admin
- Description: Allows the Administrative Assistant and Head of Admin to view monthly reports on driver workload, driver replacements and vehicle usage.
- Preconditions: The user is logged in.
- Postconditions: The report is displayed.
- Normal flow:
  1. Administrative Assistant selects Report. -> System displays the report types, which are Driver Workload, Driver Replacement and Vehicle Usage.
  2. Administrative Assistant selects a report type and the month. -> System displays the report for that month.
- Alternative flows:
  - A1 Driver Workload. At step 2, the system displays the number of request tasks, internal tasks, welfare duties and weekend tasks for each driver.
  - A2 Driver Replacement. At step 2, the system displays each replacement with the date, task, previous driver, new driver and reason.
  - A3 Vehicle Usage. At step 2, the system displays the number of trips and total mileage for each vehicle.
  - A4 Export. After step 2, the Administrative Assistant selects Export (UC012).
- Exceptions:
  - 2.1 No record is found for the selected month. The system displays a message that no record is found.
- Relationships: <<extend>> UC012 Export Report
- Notes:
  - The reports replace the manual counting of tasks in the spreadsheet.

### UC012 Export Report
- Actor: Administrative Assistant, Head of Admin
- Description: Allows the user to export a displayed report as a PDF or Excel file.
- Preconditions: A report is displayed in View Report (UC011).
- Postconditions: The report file is downloaded.
- Normal flow:
  1. Administrative Assistant selects Export, then selects PDF or Excel. -> System generates the report file. System downloads the file.
- Notes:
  - This use case extends UC011 View Report.

### UC013 Register Account
- Actor: Staff, Head of Department
- Description: Allows a first-time user to register an account after verifying the staff ID and UIS email with a verification code.
- Preconditions: The user has a UIS staff ID and UIS email and has not registered before.
- Postconditions: The account is created with the selected role.
- Normal flow:
  1. User selects First Time User? Register Now. -> System displays the verification form.
  2. User enters the staff ID and UIS email, then selects Request Code. -> System checks that the staff ID and email are not registered. System sends a verification code to the email (UC014).
  3. User enters the verification code, then selects Submit. -> System checks the code and displays the registration form.
  4. User enters the name and phone number, selects the department and role, enters the password and confirm password, then selects Register. -> System creates the account with the selected role. System displays the login page with a success message.
- Alternative flows:
  - A1 Resend code. At step 3, the user did not receive the code or the code has expired. The user selects Resend Code. The system sends a new verification code.
- Exceptions:
  - 2.1 The staff ID or email is already registered. The system displays a message that the account already exists.
  - 2.2 The email is not a UIS email. The system displays a message that only UIS email is accepted.
  - 3.1 The verification code is wrong. The system displays a message that verification failed and asks the user to try again.
  - 4.1 The passwords do not match. The system displays an error message.
  - 4.2 The user selects Head of Department for a department that already has one. The system displays a message that the department already has a Head of Department.
- Relationships: <<include>> UC014 Send Verification Email
- Notes:
  - The role options are Staff and Head of Department. A user who does not change the role is registered as Staff.
  - Driver accounts are registered by the Administrative Assistant in UC001. The Administrative Assistant and Head of Admin accounts are created during the initial setup of the system.

### UC014 Send Verification Email
- Actor: Staff, Driver, Administrative Assistant
- Description: Sends a verification code to the user's UIS email so that the user can register an account or reset the password.
- Preconditions: The user has requested a code in UC013 or UC015.
- Postconditions: The verification code is sent to the user's UIS email.
- Normal flow:
  1. User selects Request Code. -> System generates a verification code with an expiry time. System sends the code to the user's UIS email. System displays a field to enter the code.
- Exceptions:
  - 1.1 The email cannot be sent. The system displays a message to try again later.
- Notes:
  - This use case is included in UC013 Register Account and UC015 Reset Password.
  - A new code replaces any earlier code for the same user.

### UC015 Reset Password
- Actor: Staff, Driver, Administrative Assistant
- Description: Allows a user who forgot their password to set a new password using a verification code sent to their UIS email.
- Preconditions: The user has a registered and active account.
- Postconditions: The password is changed.
- Normal flow:
  1. User selects Forgot Password? on the login page. -> System displays the reset password form.
  2. User enters the staff ID and UIS email, then selects Request Code. -> System checks that the staff ID and email match a registered account. System sends a verification code to the email (UC014).
  3. User enters the verification code, then selects Submit. -> System checks the code and displays the new password form.
  4. User enters the new password and confirm password, then selects Save. -> System updates the password. System displays the login page with a success message.
- Alternative flows:
  - A1 Resend code. At step 3, the user did not receive the code or the code has expired. The user selects Resend Code. The system sends a new verification code.
- Exceptions:
  - 2.1 The staff ID and email do not match any account. The system displays a message that the account is not found.
  - 3.1 The verification code is wrong. The system displays a message that verification failed and asks the user to try again.
  - 4.1 The passwords do not match. The system displays an error message.
- Relationships: <<include>> UC014 Send Verification Email
- Notes:
  - Login is not a use case. It is a precondition of the other use cases and is covered in Section 4.2.

### UC016 Manage Profile
- Actor: Staff, Driver, Administrative Assistant
- Description: Allows a user to view and update their own profile and change their password.
- Preconditions: The user is logged in.
- Postconditions: The profile is displayed or updated.
- Normal flow:
  1. User selects Profile. -> System displays the staff ID, name, UIS email, phone number, address, department and role. For a driver, the system also displays the licence class, licence expiry date and start date of service.
- Alternative flows:
  - A1 Update. The user selects Edit. The system displays the profile form. The user updates the name, phone number and address, then selects Save. The system updates the profile and displays a success message.
  - A2 Change password. The user selects Change Password. The user enters the current password, new password and confirm password, then selects Save. The system updates the password and displays a success message.
- Exceptions:
  - A1.1 A required field is empty. The system displays a message to fill in all required fields.
  - A2.1 The current password is wrong or the new passwords do not match. The system displays an error message.
- Notes:
  - Users cannot change their own staff ID, UIS email, department or role.
  - A driver's licence details are updated by the Administrative Assistant in UC001.

### UC017 View Request
- Actor: Staff, Driver, Administrative Assistant
- Description: Allows a user to view the list of requests and the details of each request, including its current status and status history.
- Preconditions: The user is logged in.
- Postconditions: The request details are displayed.
- Normal flow:
  1. User selects My Request. -> System displays the user's own requests with their request ID, vehicle type, departure date and status.
  2. User filters the list by status or date. -> System displays the matching requests.
  3. User selects a request. -> System displays the request details, supporting document, assigned driver and vehicle, comments and status history with the reason for each change.
- Alternative flows:
  - A1 Department requests. At step 1, a Head of Department selects Department Request. The system displays the requests from the user's department.
  - A2 All requests. At step 1, the Administrative Assistant or Head of Admin selects All Request. The system displays the requests from all departments.
  - A3 Print claim approval. At step 3, the request is an approved Pay and Claim request. The user selects Print Claim Approval. The system displays the claim approval letter with the request details, approval dates and approvers. The user prints or downloads it as a PDF.
  - A4 Print rental approval. At step 3, the request is an approved Personal Rental. The user selects Print Rental Approval. The system displays the approval letter with the distance and the charge. The user prints or downloads it as a PDF.
- Exceptions:
  - 2.1 No request matches the filter. The system displays a message that no record is found.
- Notes:
  - The request status is Pending, Approved by Head of Department, Driver Assigned, Approved, Rejected, Cancelled, Lapsed or Completed.
  - A lapsed request is shown with its own status, so staff know that it was not cancelled by the Vehicle Section.
  - The claim payment and the rental payment are handled by the Bursary outside the system.

### UC018 Submit Vehicle Request
- Actor: Staff, Driver
- Description: Allows a user to submit a vehicle request. The user selects the vehicle type and whether a driver is needed, then fills in the trip details. Lorries can also be rented for personal use at a charge per kilometre.
- Preconditions: The user is logged in.
- Postconditions: The request is saved with the Pending status. The requested vehicles are reserved for the trip period. The approval email is sent.
- Normal flow:
  1. User selects New Request. -> System displays the vehicle types, which are car, MPV, van, bus and lorry.
  2. User selects a vehicle type. -> System displays the request form.
  3. User selects the purpose type, enters the purpose, destination with full address, waiting location, departure date and time, return date and time, number of passengers and quantity of vehicles, uploads the supporting letter, then selects Submit. -> System checks the availability of the vehicles for the trip period. System saves the request with the Pending status. System reserves the requested quantity of the vehicle type for the trip period. System sends an approval email to the Head of Department (UC019).
- Alternative flows:
  - A1 Pay and Claim. At step 2, the user selects a car. The system displays With Driver and Pay and Claim. The user selects Pay and Claim. The system records the request as Pay and Claim, where the user uses their own car.
  - A2 Self Drive lorry. At step 2, the user selects a lorry and the user is a driver with a GDL licence. The system displays With Driver and Self Drive.
  - A3 Lorry purpose. At step 3, the user selects a lorry. The system displays three purpose types, which are Official, Unofficial and Personal Rental.
  - A4 Personal Rental. At step 3, the user selects Personal Rental. The user enters the destination with full address, round-trip distance, departure date and time and return date and time, then selects Submit. The system displays the day of the trip and the estimated charge. The system saves the request and sends it to the Administrative Assistant.
  - A5 Urgent request. At step 3, the departure date is less than two days away. The system displays a warning and marks the request as Urgent.
- Exceptions:
  - 3.1 A required field is empty. The system displays a message to fill in all required fields.
  - 3.2 The purpose type is Official or Unofficial and no supporting letter is uploaded. The system displays a message that the supporting letter is required.
  - 3.3 The return date and time is earlier than the departure date and time. The system displays an error message.
  - 3.4 Not enough vehicles of the selected type are available for the trip period. The system displays a message that the vehicles are not available.
- Relationships: <<include>> UC019 Send Approval Email; <<extend>> UC020 Send Approval Reminder Email
- Notes:
  - Official refers to an official university programme. Unofficial refers to a programme that is not official. Both require a supporting letter. Personal Rental does not.
  - The vehicle is reserved when the request is submitted, so vehicles are given on a first-submitted, first-served basis. The reservation covers the departure time until one hour after the return time.
  - If the Head of Department does not approve the request within two days, the request changes to Lapsed and the reservation is released. The reservation is also released if the request is cancelled or rejected. Pay and Claim requests do not reserve any vehicle.
  - Pay and Claim and Self Drive requests go to the Head of Department, then the Head of Admin.
  - Personal Rental requests go to the Administrative Assistant, then the Head of Admin. The charge is RM3 per kilometre for the round-trip distance.
  - A request from a Head of Department is sent to the head of that Head of Department for approval.
  - All requests are made in the system, including urgent requests. The paper form is no longer used.

### UC019 Send Approval Email
- Actor: Staff, Driver
- Description: Sends an email to the approver when a new request is submitted, so the approver can act on it.
- Preconditions: A request has been submitted in UC018.
- Postconditions: The approval email is sent to the approver.
- Normal flow:
  1. User selects Submit in UC018. -> System finds the approver for the request. System sends an email with the request details and a link to the request. System records the date and time the email was sent.
- Alternative flows:
  - A1 Personal Rental. At step 1, the request is a Personal Rental. The system sends the email to the Administrative Assistant instead of the Head of Department.
- Exceptions:
  - 1.1 The email cannot be sent. The system keeps the request and shows it in the approver's pending list. The system tries to send the email again later.
- Notes:
  - This use case is included in UC018 Submit Vehicle Request.
  - The approver also receives an in-system notification.

### UC020 Send Approval Reminder Email
- Actor: Head of Department
- Description: Sends a reminder email to the Head of Department when a request is still waiting for approval after one day.
- Preconditions: The request status is Pending. One day has passed since the request was submitted.
- Postconditions: The reminder email is sent to the Head of Department.
- Normal flow:
  1. Head of Department has not acted on a request for one day. -> System checks the pending requests every hour. System finds requests that have been pending for one day. System sends a reminder email to the Head of Department with the time left before the request lapses.
- Exceptions:
  - 1.1 The email cannot be sent. The system tries to send the email again at the next check.
- Notes:
  - This use case extends UC018 Submit Vehicle Request.
  - The reminder is sent once for each request. If the request is still not approved after two days, it changes to Lapsed.

### UC021 Cancel Request
- Actor: Staff, Driver
- Description: Allows a user to cancel their own request before the trip. Also allows the Head of Department to cancel a request from their department. A reason is required for every cancellation.
- Preconditions: The user is logged in. The request has not started and is not Rejected, Cancelled, Lapsed or Completed.
- Postconditions: The request status is changed to Cancelled. The vehicle reservation is released.
- Normal flow:
  1. User selects My Request, then selects a request. -> System displays the request details.
  2. User selects Cancel Request. -> System displays a pop-up form to enter the reason for cancellation.
  3. User enters the reason, then selects Confirm. -> System changes the status to Cancelled and records the reason. System releases the vehicle reservation. System sends a notification to the related users.
- Alternative flows:
  - A1 Head of Department. At step 1, a Head of Department selects Department Request, then selects a request from their department. The flow continues from step 2.
  - A2 Back. At step 3, the user selects Back. The system closes the pop-up form without cancelling.
- Exceptions:
  - 3.1 The reason is empty. The system displays a message that the reason is required.
- Notes:
  - The related users are the requester, the Head of Department, the Administrative Assistant and the assigned driver, if any.
  - The reason is shown in the status history and in the notification, so the requester knows who cancelled the request and why.

### UC022 Request Schedule Change
- Actor: Driver
- Description: Allows a driver to inform the Vehicle Section of dates when they cannot work, such as a weekend, or to ask to be removed from a task assigned to them.
- Preconditions: The driver is logged in.
- Postconditions: The schedule change request is sent to the Head of Admin with the Pending status.
- Normal flow:
  1. Driver selects Schedule Change, then selects New Request. -> System displays the schedule change form.
  2. Driver selects the unavailable date or dates, enters the reason, then selects Submit. -> System saves the request with the Pending status. System sends a notification to the Head of Admin.
- Alternative flows:
  - A1 Remove from a task. At step 1, the driver selects My Task, selects the task, then selects Request Change. The system displays the form with the task details. The driver enters the reason, then selects Submit. The system saves the request and sends a notification to the Head of Admin.
  - A2 View or cancel. At step 1, the driver selects Schedule Change. The system displays the driver's schedule change requests and their status. The driver selects a Pending request, then selects Cancel. The system cancels the request.
- Exceptions:
  - 2.1 The reason is empty or no date is selected. The system displays a message to fill in all required fields.
  - 2.2 The selected date has already passed. The system displays an error message.
- Notes:
  - Schedule changes are approved by the Head of Admin in UC027.
  - Drivers with an approved schedule change are not recommended for tasks on those dates.

### UC023 View Assigned Task
- Actor: Driver
- Description: Allows a driver to view the tasks assigned to them, including request tasks, internal tasks and welfare duty.
- Preconditions: The driver is logged in.
- Postconditions: The assigned tasks are displayed.
- Normal flow:
  1. Driver selects My Task. -> System displays the driver's upcoming approved tasks with their date, time, task type and vehicle.
  2. Driver selects a task. -> System displays the date, time, vehicle, destination with full address, waiting location, purpose, officer name and officer phone number.
- Alternative flows:
  - A1 Past tasks. At step 1, the driver selects Past Tasks. The system displays the driver's completed tasks.
- Exceptions:
  - 1.1 The driver has no upcoming task. The system displays a message that no task is assigned.
- Notes:
  - The task details replace the WhatsApp messages typed by hand.

### UC024 Manage Assigned Task Report
- Actor: Driver
- Description: Allows a driver to create, view and update the report of a completed task. The report records the trip details and updates the vehicle's mileage, fuel level and status.
- Preconditions: The driver is logged in. The task has been approved and assigned to the driver.
- Postconditions: The report is created or updated. The vehicle's mileage, fuel level and status are updated.
- Normal flow:
  1. Driver selects My Task, then selects a task. -> System displays the task details.
  2. Driver selects Create Report. -> System displays the task report form with the current mileage of the vehicle.
  3. Driver enters the actual departure time, actual return time, end mileage, fuel level and vehicle condition, then selects Submit. -> System saves the report and changes the task status to Completed. System updates the vehicle's current mileage, fuel level and status. System sends a notification to the Administrative Assistant.
- Alternative flows:
  - A1 Vehicle problem. At step 3, the driver selects Problem as the vehicle condition. The system displays the vehicle problem form. The driver selects the problem type, enters the description, uploads a photo and states whether the vehicle can still be used, then selects Submit. The system records the problem and highlights the report to the Administrative Assistant.
  - A2 Vehicle cannot be used. At step 3, the driver states that the vehicle cannot be used. The system changes the vehicle status to Under Maintenance.
  - A3 View. At step 1, the driver selects Past Tasks, then selects a task. The system displays the task report.
  - A4 Update. The driver selects a task report, then selects Edit. The driver updates the details, then selects Save. The system updates the report and the vehicle details, and sends a notification to the Administrative Assistant.
- Exceptions:
  - 1.1 The task has not reached its departure time. The system does not show the Create Report button.
  - 3.1 A required field is empty. The system displays a message to fill in all required fields.
  - 3.2 The end mileage is lower than the current mileage. The system displays a message that the mileage is not valid.
- Notes:
  - The report applies to request tasks, internal tasks and welfare duty.
  - The problem types are Plate Number, Tyre, Lights, Body Damage, Engine and Others. If the vehicle can still be used, its status stays Active.
  - A task report cannot be deleted because it updates the vehicle's records.
  - This use case was added on the supervisor's advice to keep the vehicle mileage and fuel level up to date.

### UC025 Approve/Reject Request
- Actor: Head of Department, Head of Admin
- Description: Allows the Head of Department to approve or reject a request from their department. Also allows the Head of Admin to approve or reject Pay and Claim and Self Drive requests after the Head of Department approves them.
- Preconditions: The user is logged in. The request is waiting for the user's approval.
- Postconditions: The request status is updated.
- Normal flow:
  1. Head of Department selects Department Request. -> System displays the pending requests from the department, with Urgent requests at the top.
  2. Head of Department selects a request. -> System displays the request details and supporting letter.
  3. Head of Department selects Approve. -> System changes the status to Approved by Head of Department. System sends a notification to the requester and the Administrative Assistant.
- Alternative flows:
  - A1 Reject. At step 3, the Head of Department selects Reject. The system displays the comment box (UC028). The Head of Department enters the reason, then selects Confirm. The system changes the status to Rejected, releases the vehicle reservation and sends a notification to the requester with the reason.
  - A2 Pay and Claim or Self Drive. At step 3, the request is Pay and Claim or Self Drive. The system sends the request to the Head of Admin instead of the Administrative Assistant.
  - A3 Head of Admin approval. The Head of Admin selects Pending Approval, selects a Pay and Claim or Self Drive request, then selects Approve or Reject. The system updates the status and sends a notification to the requester.
- Exceptions:
  - A1.1 The reason is empty when rejecting. The system displays a message that the reason is required.
- Relationships: <<extend>> UC028 Add Comment (extension point Reject)
- Notes:
  - No comment is needed when a request is approved.
  - A request that is not approved by the Head of Department within two days changes to Lapsed, and the vehicle reservation is released.
  - Requests with a driver are approved by the Head of Admin in UC026, after the Administrative Assistant assigns the driver.

### UC026 Approve/Reject Driver Assignment
- Actor: Head of Admin
- Description: Allows the Head of Admin to approve or reject the vehicle and driver assigned by the Administrative Assistant for request tasks, internal tasks, welfare duty and driver replacements.
- Preconditions: The Head of Admin is logged in. The task status is Driver Assigned.
- Postconditions: The task status is updated.
- Normal flow:
  1. Head of Admin selects Pending Approval. -> System displays the tasks with assigned drivers, with Urgent requests at the top.
  2. Head of Admin selects a task. -> System displays the task details, assigned vehicles, assigned drivers and each driver's task count this month.
  3. Head of Admin selects Approve. -> System changes the status to Approved. System sends a notification to the requester, the assigned drivers and the Administrative Assistant.
- Alternative flows:
  - A1 Reject. At step 3, the Head of Admin selects Reject. The system displays the comment box (UC028). The Head of Admin enters the reason, then selects Confirm. The system returns the task to the Administrative Assistant with the reason, so a new driver can be assigned. The vehicle reservation is kept.
- Exceptions:
  - A1.1 The reason is empty when rejecting. The system displays a message that the reason is required.
- Relationships: <<extend>> UC028 Add Comment (extension point Reject)
- Notes:
  - Rejecting a driver assignment does not reject the request. It only returns the task to the Administrative Assistant.
  - The Head of Admin knows which drivers are on leave, so this approval checks that the assigned drivers are available.
  - This use case also approves driver replacements made in UC008.

### UC027 Approve/Reject Schedule Change
- Actor: Head of Admin
- Description: Allows the Head of Admin to approve or reject a schedule change requested by a driver.
- Preconditions: The Head of Admin is logged in. The schedule change request status is Pending.
- Postconditions: The schedule change request status is updated.
- Normal flow:
  1. Head of Admin selects Schedule Change. -> System displays the pending schedule change requests.
  2. Head of Admin selects a request. -> System displays the driver, dates, reason and any tasks the driver has on those dates.
  3. Head of Admin selects Approve. -> System changes the status to Approved. System marks the driver as unavailable on those dates. System sends a notification to the driver.
- Alternative flows:
  - A1 Driver has a task. At step 3, the driver has a task on the approved date. The system sends the task to the Administrative Assistant to replace the driver (UC008).
  - A2 Reject. At step 3, the Head of Admin selects Reject. The system displays the comment box (UC028). The Head of Admin enters the reason, then selects Confirm. The system changes the status to Rejected and sends a notification to the driver with the reason.
- Exceptions:
  - A2.1 The reason is empty when rejecting. The system displays a message that the reason is required.
- Relationships: <<extend>> UC028 Add Comment (extension point Reject)
- Notes:
  - A driver marked as unavailable is not recommended for tasks on those dates.

### UC028 Add Comment
- Actor: Head of Department, Head of Admin
- Description: Allows the approver to enter the reason when rejecting a request, a driver assignment or a schedule change.
- Preconditions: The approver has selected Reject in UC025, UC026 or UC027.
- Postconditions: The reason is saved and shown to the affected users.
- Normal flow:
  1. Approver selects Reject. -> System displays the comment box.
  2. Approver enters the reason, then selects Confirm. -> System saves the reason with the date, time and approver name. System returns to the use case that called it.
- Alternative flows:
  - A1 Back. At step 2, the approver selects Back. The system closes the comment box without rejecting.
- Exceptions:
  - 2.1 The reason is empty. The system displays a message that the reason is required.
- Notes:
  - This use case extends UC025, UC026 and UC027. It is used only when rejecting.
  - Cancel Request (UC021) uses its own pop-up form for the reason and does not use this use case.

## Data model (class diagram)

- **Department**: departmentID : int, departmentName : String, headUserID : int
- **User**: userID : int, staffID : String, name : String, email : String, phoneNo : String, address : String, password : String, departmentID : int, role : String, status : String
- **VerificationCode**: codeID : int, email : String, code : String, purpose : String, expiryTime : DateTime
- **Notification**: notificationID : int, userID : int, message : String, dateSent : DateTime, isRead : boolean, emailSent : boolean
- **DriverProfile**: driverID : int, userID : int, licenceClass : String, licenceExpiry : Date, startDate : Date
- **Vehicle**: vehicleID : int, plateNo : String, type : String, brand : String, model : String, fuelType : String, capacity : int, licenceRequired : String, currentMileage : int, fuelLevel : String, status : String
- **Request**: requestID : int, requesterID : int, vehicleType : String, quantity : int, driverOption : String, purposeType : String, purpose : String, destination : String, address : String, waitingLocation : String, departDateTime : DateTime, returnDateTime : DateTime, noOfPassengers : int, supportingDoc : String, distanceKm : double, charge : double, isUrgent : boolean, status : String, dateSubmitted : DateTime
- **VehicleReservation**: reservationID : int, requestID : int, vehicleID : int, vehicleType : String, startDateTime : DateTime, endDateTime : DateTime, status : String
- **Task**: taskID : int, requestID : int, dutyID : int, taskSource : String, taskType : String, tripType : String, driverID : int, vehicleID : int, startDateTime : DateTime, endDateTime : DateTime, description : String, approvedBy : int, status : String
- **WelfareDuty**: dutyID : int, weekStartDate : Date, status : String
- **DriverReplacement**: replacementID : int, taskID : int, previousDriverID : int, newDriverID : int, reason : String, replacedBy : int, dateTime : DateTime, status : String
- **ScheduleChange**: changeID : int, driverID : int, taskID : int, startDate : Date, endDate : Date, reason : String, status : String, comment : String
- **TaskReport**: reportID : int, taskID : int, actualDepart : DateTime, actualReturn : DateTime, endMileage : int, fuelLevel : String, condition : String, problemType : String, problemDesc : String, photo : String, canBeUsed : boolean
- **StatusHistory**: historyID : int, requestID : int, userID : int, action : String, reason : String, dateTime : DateTime

Associations:
- Department 1 — 0..* User (has)
- User 1 — 0..* VerificationCode (verifies)
- User 1 — 0..* Notification (receives)
- User 1 — 0..1 DriverProfile (is a)
- User 1 — 0..* Request (submits)
- Request 1 — 0..* StatusHistory (records)
- Request 1 — 0..* VehicleReservation (reserves)
- Vehicle 1 — 0..* VehicleReservation (held by)
- Request 1 — 0..* Task (has)
- WelfareDuty 1 — 1..7 Task (has)
- Vehicle 1 — 0..* Task (used in)
- DriverProfile 1 — 0..* Task (performs)
- Task 1 — 0..1 TaskReport (reported in)
- Task 1 — 0..* DriverReplacement (replaced through)
- DriverProfile 1 — 0..* ScheduleChange (requests)

## Non-functional requirements (summary)

- Up to 50 concurrent users; vehicle availability and driver recommendation within 3 seconds; dashboard within 5 seconds; notifications within 1 minute; monthly report within 10 seconds.
- Responsive pages (desktop and smartphone); driver pages simple and readable on mobile.
- HTTPS, hashed passwords, prepared statements, input validation with clear error messages, audit records cannot be edited or deleted.
- Prevent two requests/tasks holding the same vehicle or driver in the same period, even when submitted at the same time (use transactions/locking).
- PDPA compliance; daily backup including uploaded documents.