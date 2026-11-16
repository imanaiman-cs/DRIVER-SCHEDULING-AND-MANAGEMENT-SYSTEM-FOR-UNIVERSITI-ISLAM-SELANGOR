# SRS — Driver Scheduling and Management System (DSMS), version 1.1

Converted from `SRS DSMS UIS v1.1 final.docx` (22 October 2026) so that Claude Code can read it.
The docx is the official version; figures and diagrams are only in the docx. Appendix B (interview evidence) is left out.

## 1. Introduction

### Document Purpose

This Software Requirements Specification (SRS) document describes the functional and non-functional requirements of the Driver Scheduling and Management System (DSMS), version 1.1. The system is developed for the Vehicle Section of Universiti Islam Selangor (UIS). The purpose of this document is to state the complete set of requirements for the system. It explains the objectives of the system, the users and their needs, and the features and constraints of the system.

DSMS is designed to replace the monthly spreadsheet and WhatsApp messages that the Vehicle Section currently uses to schedule drivers and assign tasks. The current e-Kenderaan system only handles vehicle requests and their approval. DSMS covers the full process, from the vehicle request and approval to driver assignment, task delivery and monthly reporting.

This SRS covers the whole system. It includes driver management, vehicle management, vehicle requests, request approval, driver assignment, task scheduling, driver schedule changes and reports.

### Product Scope

DSMS is a web-based system for the UIS Vehicle Section. It helps the Administrative Assistant and the Head of Admin manage vehicle requests, drivers, vehicles and tasks in one place. Staff use the system to request vehicles and follow the status of their requests. Heads of Department use it to approve requests. Drivers use it to view their tasks.

The system locks a vehicle for the requested time period once a request is made, which prevents booking clashes. Approved paper form requests are also recorded so that they do not clash with online requests. The system tracks the status of each vehicle, including vehicles under maintenance. It recommends a suitable driver for each trip based on licence class, availability, the number of tasks this month, weekend tasks and experience. It records every driver replacement and cancellation with a reason. Drivers can inform the Vehicle Section of dates they cannot work. The system also sends notifications by email and within the system. The system aims to reduce manual work, prevent clashes and make task distribution among drivers fairer.

### Intended Audience and Document Overview

This document is intended for everyone involved in the development, testing and use of DSMS. The main reader is the developer, who builds the system based on these requirements. Testers use this document to check that the system works as required. The supervisor and examiner use it to evaluate the project. Stakeholders at the UIS Vehicle Section use it to confirm that the system meets their needs.

This document has five sections and two appendices. Section 1 introduces the document, the scope of the system, the definitions used and the references. Section 2 describes the system as a whole, including its users, operating environment, constraints, assumptions and dependencies. Section 3 describes the functional requirements through the use case diagram and use case descriptions. Section 4 lists the non-functional requirements, such as performance, security and software quality. Section 5 lists other requirements, such as legal requirements and backup. Appendix A contains the requirement models, which are the activity diagrams, class diagram and system sequence diagrams. Appendix B contains the requirement gathering evidence.

Readers should start with Sections 1 and 2 to understand the purpose and context of the system. They can then read Sections 3 and 4 based on their role in the project.

### Definitions Acronyms and Abbreviations

| TERMS                    | DESCRIPTION                                                                                                                |
|--------------------------|----------------------------------------------------------------------------------------------------------------------------|
| DSMS                     | Driver Scheduling and Management System, the system described in this document.                                            |
| UIS                      | Universiti Islam Selangor.                                                                                                 |
| BPI                      | Bahagian Pengurusan Insan, the human resource division that oversees the Vehicle Section.                                  |
| User                     | Any person who uses the system, including staff, head of department, head of admin, administrative assistant and driver.   |
| Staff                    | A UIS staff member who submits transport requests.                                                                         |
| Head of Department       | Head of the staff member’s department, who approves or rejects transport requests from their own staff.                    |
| Head of Admin            | Head of BPI, who approves driver assignments and driver schedule change requests.                                          |
| Administrative Assistant | Vehicle Section officer who assigns drivers and manages driver and vehicle records.                                        |
| Driver                   | Carpool driver who carries out trips and internal tasks, views assigned tasks and requests schedule changes in the system. |
| e-Kenderaan              | The current UIS online vehicle request system.                                                                             |
| Welfare Duty             | A daily 24-hour duty in which one carpool driver takes people to the hospital or clinic.                                   |
| Driver Replacement       | A change of the assigned driver after a task has been assigned.                                                            |
| Paper Form               | The official vehicle booking form BPTK/BPI/2025/PIN.5, used for requests made less than one day before the trip.           |
| Schedule Change Request  | A request by a driver to mark dates as unavailable or to withdraw from an assigned task.                                   |
| Vendor Bus               | A bus hired from an outside vendor when no UIS driver with a PSV licence is available.                                     |
| Class D Licence          | Malaysian driving licence for cars and vans.                                                                               |
| PSV Licence              | Public Service Vehicle licence, required to drive buses.                                                                   |
| GDL Licence              | Goods Driving Licence, required to drive lorries.                                                                          |
| PDPA                     | Personal Data Protection Act 2010 of Malaysia.                                                                             |
| Use Case                 | A description of how a user interacts with the system to achieve a goal.                                                   |
| Activity Diagram         | A diagram that shows the flow of activities in a process.                                                                  |
| Class Diagram            | A diagram that shows the classes of a system, their attributes, methods and relationships.                                 |
| System Sequence Diagram  | A diagram that shows the order of messages between a user and the system for one use case.                                 |

### Document Conventions

This document follows the IEEE format for software requirements specifications. The font used is Arial size 11 for normal text, and headings are in bold. Each use case is given a unique ID that starts with UC, for example UC001. The activity diagrams and system sequence diagrams in Appendix A use the same number as their use case, for example AD016 and SSD016 for UC016. The word “shall” is used to state a requirement that the system must meet.

### References and Acknowledgements

#### References

Requirement gathering interviews with the Administrative Assistant and the Head Driver, UIS Vehicle Section, 8 to 19 October 2026.

e-Kenderaan system, Universiti Islam Selangor, observed on 8 October 2026.

Driver schedules for May to September 2026 (Jadual Pemandu 2026), UIS Vehicle Section.

Jabatan Perlindungan Data Peribadi. (2024). Personal data protection. https://www.pdp.gov.my/ppdpv1/en/main-page/

#### Acknowledgements

> The author would like to thank En. Khairul Hafizi Bin Jamal, Administrative Assistant of the UIS Vehicle Section, and En. Mohd Noh Bin Mohamad Sulam, Head Driver of the UIS Vehicle Section, for their time and information during the requirement gathering. The author would also like to thank the supervisor, Pn. Faiqah Hafidzah Binti Halim, for her guidance throughout this project.

## 2. Overall Description

### Product Perspective

The Driver Scheduling and Management System (DSMS) is a new, self-contained web-based system developed for the Vehicle Section of Universiti Islam Selangor (UIS). It replaces the monthly spreadsheet and WhatsApp messages that the Vehicle Section currently uses to schedule drivers, assign tasks and inform drivers of changes.

UIS staff currently request vehicles through e-Kenderaan, a system owned by the UIS IT Department. e-Kenderaan only handles vehicle requests and their approval. It does not support driver scheduling, driver recommendation, vehicle maintenance status or communication with drivers. The Vehicle Section cannot modify e-Kenderaan because it belongs to the IT Department. For this reason, DSMS is developed as a separate system and is not an extension of e-Kenderaan.

DSMS covers the full process, from the vehicle request and approval to driver assignment, task delivery and monthly reporting. All data is stored in a central MySQL database. Each user can only access the functions allowed for their role. The system is accessed through a web browser on computers and smartphones, and it sends notifications within the system and by email. User accounts and roles are provided by the existing UIS staff system managed by the IT Department. DSMS does not integrate with e-Kenderaan or the UIS leave system at this stage.

Figure 2.1: Product Perspective of Driver Scheduling and Management System

Figure 2.1 shows the product perspective of DSMS. All users access DSMS through a web browser by sending HTTP requests and receiving HTTP responses. DSMS receives user account data from the UIS staff system. It stores and retrieves all data through queries to a centralized MySQL database, and it sends email notifications through the email server.

### Product Functionality

The Driver Scheduling and Management System (DSMS) provides a centralized platform to manage staff transport requests and carpool driver tasks for the UIS Vehicle Section. It supports five types of users, namely staff, head of department, head of admin, administrative assistant and driver. The system offers the following major functionalities:

1.  **Notifications**

    - Send email and in-system notifications when the status of a request, assignment or task changes.

    - Send the approval email to the Head of Department when a request is submitted.

2.  **Driver Management**

    - Add drivers from existing UIS staff accounts, view and update driver profiles, and deactivate drivers.

    - Record the licence classes held by each driver (D, GDL, PSV).

    - Allow drivers to update their own phone number and licence details.

3.  **Vehicle Management**

    - Add, view, update and deactivate vehicle records, including vehicle type and passenger capacity.

    - Update the vehicle status as Available or Under Maintenance.

    - Allow drivers to record mileage, fuel level and vehicle condition after a task.

4.  **Vehicle Request**

    - Submit vehicle requests with trip dates, times, destination, purpose and passengers.

    - View only vehicles that are available and suitable for the number of passengers.

    - Choose a waiting location from a fixed list or enter another address, and upload supporting documents.

    - View the status of requests and cancel requests.

    - Request a vehicle with or without a driver.

    - Record approved paper form requests so that the vehicle is locked.

5.  **Request Approval**

    - Approve or reject requests, with a reason for each rejection.

    - Add comments for the staff member when approving, rejecting or cancelling a request.

    - Send an automatic reminder to the Head of Department when a request is still pending after one day.

    - Change unapproved requests to Lapsed after two days.

6.  **Driver Assignment**

    - View a ranked recommendation of drivers based on licence class, availability and a weighted score of tasks this month, weekend tasks and experience.

    - Assign drivers separately for the outbound and return trips.

    - Record a vendor bus when no UIS driver with a PSV licence is available.

    - Approve or reject driver assignments.

    - Replace drivers with a reason.

7.  **Task Scheduling**

    - Add, view, update and delete internal tasks such as workshop runs, refuelling and test drives.

    - Set and update the 24-hour welfare duty driver for each day.

    - View all trips, internal tasks and welfare duty in one schedule calendar.

    - Allow drivers to view their own tasks with full details.

    - Allow drivers to mark unavailable dates or ask to withdraw from a task, for approval by the Head of Admin.

8.  **Reports**

    - Generate monthly reports of tasks per driver, weekend tasks per driver, welfare duty days, driver replacements and vehicle usage.

    - Export reports as PDF or Excel files, or print them.

> Figure 2.2: DFD Level 0 of Driver Scheduling and Management System

Figure 2.2 shows the DFD Level 0 of DSMS. Staff send vehicle request details, supporting documents and cancellations, and receive available vehicles, request status and notifications. Heads of Department send approval decisions with comments, and receive pending requests, approval emails and reminder emails. The Administrative Assistant sends driver and vehicle records, paper form requests, driver assignments, replacements, internal tasks, welfare duty and report requests, and receives driver recommendations, the schedule calendar and reports. The Head of Admin sends decisions on driver assignments and schedule change requests, and receives them for approval. Drivers send profile updates, vehicle mileage and condition, and schedule change requests, and receive task details and notifications. The UIS staff system provides staff account data, and the email server delivers email notifications.

### Users and Characteristics

The Driver Scheduling and Management System (DSMS) will be used by five types of users in Universiti Islam Selangor (UIS), each with different responsibilities, levels of access and technical skills.

1.  **Staff.**

> Staff are UIS employees who need a vehicle for official trips, department programmes or student activities. Drivers and Heads of Department also use the system as staff when they request a vehicle. Staff can submit vehicle requests, view the status of their requests and cancel them. They can also see the reason and comments when a request is rejected or cancelled. Staff are expected to have basic computer skills and use the system only when they need a vehicle.

2.  **Head of Department.**

> The Head of Department is the head of the staff member’s PTJ. This user approves or rejects vehicle requests from their department, cancels requests with a reason and adds comments for the staff member. A request from a Head of Department is approved by the head of that Head of Department. Heads of Department are expected to have basic computer skills and use the system whenever a new request is submitted.

3.  **Administrative Assistant.**

> The Administrative Assistant is the main user of the system. This user manages drivers and vehicles, assigns and replaces drivers, cancels requests with a reason, adds comments and records approved paper form requests. The Administrative Assistant also adds internal tasks, sets welfare duty, views the schedule calendar and generates reports. The Administrative Assistant is expected to have a medium level of computer skill and uses the system every working day.

4.  **Head of Admin.**

> The Head of Admin is the head of BPI. This user approves or rejects driver assignments, because the Head of Admin knows whether a driver is on leave. The Head of Admin also approves or rejects schedule change requests from drivers and can perform all tasks of the Administrative Assistant. The Head of Admin is expected to have a medium level of computer skill and uses the system several times a week.

5.  **Driver.**

> Drivers are carpool drivers of the Vehicle Section. They view their own tasks, receive notifications, update their own profile, record vehicle mileage and condition, and request schedule changes. They do not confirm tasks or update trip status in the system. Drivers have different levels of technology skill, so the interface must be simple and easy to use. They mainly use the system on their smartphones.

### Operating Environment

DSMS operates as a web-based system within UIS. It is accessed through a web browser on computers, laptops and smartphones. Users do not need to install any software or plugins on their devices.

The system is compatible with Windows 10 and above, macOS, Android and iOS. It can be accessed through Google Chrome, Mozilla Firefox, Microsoft Edge and Safari. Drivers mainly access the system through their smartphones while on duty.

The system is developed using PHP for server-side processing, with HTML, CSS and JavaScript for the interface. Data is stored in a MySQL database. XAMPP is used as the local development server. The system uses an email server to send email notifications.

DSMS coexists with other systems already used at UIS. e-Kenderaan remains in use by the IT Department. Drivers continue to use other UIS systems for clocking in and out, leave applications and payslips. Drivers also continue to record each trip in the vehicle log book. DSMS records the latest mileage and vehicle condition for servicing purposes.

Figure 2.3: High-Level System Architecture of Driver Scheduling and Management System

Figure 2.3 shows the operating environment of DSMS. Users access the system through a web browser on a computer or smartphone. The browser connects to the web server through HTTPS. The DSMS application runs on the web server with PHP and stores data in a MySQL database. The web server sends email notifications through an email server, and staff accounts and roles come from the UIS staff system. e-Kenderaan and the UIS leave system are used separately and are not integrated with DSMS.

### Design and Implementation Constraints

- **Hardware Limitations**
  - Hardware issues, such as problems with office computers or laptops, may interrupt users while entering or viewing data.
  - A slow or unstable network connection may delay updates on requests, approvals and assignments.
- **Software**
  - Some users, especially drivers, have limited experience with new software, so the interface must be kept very simple.
  - The system must work on common web browsers without any extra installation.
- **Integration**
  - e-Kenderaan is owned and managed by the UIS IT Department, so DSMS is developed as a new system.
  - Access to the UIS staff database depends on approval from the UIS IT Department.
- **Policy**
  - The two-day approval period and the advance notice rules follow current UIS procedures and cannot be changed by the system.
  - Requests made less than one day before the trip use the paper form BPTK/BPI/2025/PIN.5. The Administrative Assistant records the approved form in DSMS.
- **Tools and Database**
  - The system is limited to PHP and MySQL running on XAMPP during development.
  - Data may be lost if the database is not backed up regularly in case of a system crash.

### User Documentation

- **User Manual (PDF):** A guide for staff, Heads of Department and drivers. It explains how to log in, submit and track vehicle requests, approve requests and view tasks, with screenshots and step-by-step instructions.

- **Administrator Guide (PDF):** A guide for the Administrative Assistant and the Head of Admin. It explains how to manage drivers and vehicles, assign and replace drivers, set welfare duty, add internal tasks and generate reports.

- **Quick Guide for Drivers:** A one-page guide on how to view tasks, update vehicle mileage and request schedule changes on a smartphone.

### Assumptions and Dependencies

The following assumptions and dependencies apply to the development and use of DSMS. If any of them change, the scope or functions of the system may be affected.

#### Assumptions

1.  **Internet access. Users, including drivers on duty, have internet access when they use the system.**

2.  **Correct user input.** Users enter correct information, such as the full destination address and correct trip times. The accuracy of the schedule depends on the input.

3.  **Clear user roles.** User roles are set correctly in the UIS staff system.

4.  **Driver records are up to date. Drivers and the Administrative Assistant keep each driver’s licence classes and status up to date.**

5.  **Vehicle status is up to date.** The Administrative Assistant updates the vehicle status when a vehicle is sent to or returned from the workshop.

6.  **Leave is checked manually.** The Head of Admin checks driver leave in the UIS leave system before approving an assignment.

#### Dependencies

1.  **Email server.** The system depends on a working email server (SMTP) to send email notifications.

2.  **Database Management System.** The system depends on MySQL to store and retrieve all data.

3.  **Web server.** The system depends on a web server with PHP support to run.

4.  **Web browser.** Users depend on a modern web browser to access the system.

## 3. System Features

Use case diagram: see Figure 3.1 in the SRS docx. Actor generalisation: Head of Admin inherits Administrative Assistant; Driver and Head of Department inherit Staff.

Relationships: include UC010→UC011, UC014/UC015/UC030→UC016, UC017→UC018. Extend UC012→UC010, UC020→UC030, UC032→UC031.

### UC001: Add Driver

- **Actor:** Administrative Assistant, Head of Admin
- **Description:** Allows the Administrative Assistant or Head of Admin to register a staff member who joins the Vehicle Section as a driver. The staff account already exists in the UIS staff system.
- **Priority / frequency:** High / Low
- **Preconditions:**
  - The Administrative Assistant or Head of Admin is logged in.
  - The staff member has an account in the UIS staff system.
- **Postconditions:**
  - The driver profile is saved with the status Active.
  - The driver can log in and view their tasks.

**Normal flow**

1. Administrative Assistant selects Driver and clicks Add Driver.
   - 1.1 System displays the driver form.
2. Administrative Assistant enters the staff ID.
   - 2.1 System displays the staff name and ID from the UIS staff system.
3. Administrative Assistant selects the licence classes (D, GDL, PSV), and enters the licence expiry date and the date the driver joined the Vehicle Section.
4. Administrative Assistant clicks Save.
   - 4.1 System validates the entered details.
   - 4.2 System saves the driver profile with the status Active.
   - 4.3 System sends an in-system and email notification to the driver.
   - 4.4 System displays a success message.

**Alternative courses**

- Not applicable

**Exceptions**

- 2.1 The staff ID is not found in the UIS staff system. The system displays an error message.
- 4.1 The staff member is already registered as a driver. The system displays an error message.
- 4.1 No licence class is selected. The system displays an error message.

**Includes / extends**

- Not applicable

**Notes and issues**

- Driver accounts are not created in DSMS. DSMS only registers an existing staff account as a driver.
- The licence classes are used to filter drivers in the driver recommendation (UC018).
- The date joined is used to calculate experience in the weighted score (UC018).

### UC002: View Driver Profile

- **Actor:** Driver, Administrative Assistant, Head of Admin
- **Description:** Allows a driver to view their own profile. The Administrative Assistant and Head of Admin can view the profiles of all drivers, including the licence classes and number of tasks this month.
- **Priority / frequency:** Medium / Medium
- **Preconditions:**
  - The user is logged in.
- **Postconditions:**
  - The driver profile is displayed.

**Normal flow**

1. Driver selects My Profile.
   - 1.1 System displays the driver's name, staff ID, phone number, licence classes and licence expiry date.

**Alternative courses**

- A1 View all drivers. At step 1, the Administrative Assistant or Head of Admin selects Driver. The system displays the list of drivers with their licence classes and number of tasks this month. The Administrative Assistant then selects a driver and the system displays the driver profile and upcoming tasks.

**Exceptions**

- 1.1 The driver profile is not found. The system displays a message.

**Includes / extends**

- Not applicable

**Notes and issues**

- Driver accounts come from the UIS staff system. The Administrative Assistant registers a staff member as a driver in UC001.
- Only drivers have a profile in DSMS because their licence and experience are used in scheduling. Other users’ details come from the UIS staff system.
- A driver can only view their own profile.
- The task count helps the Administrative Assistant see the workload of each driver.

### UC003: Update Driver Profile

- **Actor:** Driver, Administrative Assistant, Head of Admin
- **Description:** Allows a driver to update their own profile, such as the phone number, licence classes and licence expiry date. The Administrative Assistant and Head of Admin can update the profile of any driver.
- **Priority / frequency:** Medium / Low
- **Preconditions:**
  - The user is logged in.
  - The driver profile exists in the system.
- **Postconditions:**
  - The updated driver profile is saved in the system.

**Normal flow**

1. Driver selects My Profile and clicks Update.
   - 1.1 System displays the driver profile in an editable form.
2. Driver edits the phone number, licence classes (D, GDL, PSV) or licence expiry date.
3. Driver clicks Save.
   - 3.1 System validates the entered details.
   - 3.2 System saves the changes.
   - 3.3 System sends an in-system notification to the Administrative Assistant if the licence classes are changed.
   - 3.4 System displays a success message.

**Alternative courses**

- A1 Update by Administrative Assistant. At step 1, the Administrative Assistant or Head of Admin selects a driver from the driver list (UC002) and clicks Update. The Administrative Assistant can also edit the date the driver joined the Vehicle Section. The system saves the changes and notifies the driver.

**Exceptions**

- 3.1 No licence class is selected. The system displays an error message.
- 3.1 The licence expiry date has already passed. The system displays an error message.
- 2. The user cancels the action. The system does not save the changes.

**Includes / extends**

- Not applicable

**Notes and issues**

- The driver's name and staff ID come from the UIS staff system and cannot be changed in DSMS.
- Only the Administrative Assistant and Head of Admin can edit the date the driver joined, because it is used to calculate experience in the driver recommendation (UC018).
- If a licence class is removed, the driver is no longer recommended for vehicles that need that licence (UC018).
- Driver profiles are deactivated (UC004), not deleted, so past task records are kept.

### UC004: Deactivate Driver

- **Actor:** Administrative Assistant, Head of Admin
- **Description:** Allows the Administrative Assistant or Head of Admin to deactivate a driver who no longer works in the Vehicle Section.
- **Priority / frequency:** Medium / Low
- **Preconditions:**
  - The Administrative Assistant or Head of Admin is logged in.
  - The driver is active.
- **Postconditions:**
  - The driver status is changed to Inactive.

**Normal flow**

1. Administrative Assistant selects a driver and clicks Deactivate.
   - 1.1 System checks whether the driver has upcoming tasks.
   - 1.2 System displays a confirmation message.
2. Administrative Assistant confirms the action.
   - 2.1 System changes the driver status to Inactive.
   - 2.2 System displays a success message.

**Alternative courses**

- Not applicable

**Exceptions**

- 1.1 The driver has upcoming tasks. The system displays a warning and asks the Administrative Assistant to replace the driver first (UC020).

**Includes / extends**

- Not applicable

**Notes and issues**

- Inactive drivers do not appear in the driver recommendation (UC018) and cannot log in as a driver.
- Driver records are not deleted, so past tasks remain in the records and reports.

### UC005: Add Vehicle

- **Actor:** Administrative Assistant, Head of Admin
- **Description:** Allows the Administrative Assistant or Head of Admin to add a new vehicle to the system.
- **Priority / frequency:** High / Low
- **Preconditions:**
  - The Administrative Assistant or Head of Admin is logged in.
- **Postconditions:**
  - The new vehicle is saved with the status Available.

**Normal flow**

1. Administrative Assistant selects Vehicle and clicks Add Vehicle.
   - 1.1 System displays the vehicle form.
2. Administrative Assistant enters the registration number, type, passenger capacity and required licence class.
3. Administrative Assistant clicks Save.
   - 3.1 System validates the entered details.
   - 3.2 System saves the vehicle with the status Available.
   - 3.3 System displays a success message.

**Alternative courses**

- Not applicable

**Exceptions**

- 3.1 The registration number already exists. The system displays an error message.
- 3.1 A required field is empty. The system displays an error message.

**Includes / extends**

- Not applicable

**Notes and issues**

- The vehicle type is car, van, lorry or bus.
- The passenger capacity is used to show only suitable vehicles to staff.

### UC006: View Vehicle

- **Actor:** Administrative Assistant, Head of Admin
- **Description:** Allows the Administrative Assistant or Head of Admin to view the list of vehicles and their current status.
- **Priority / frequency:** Medium / High
- **Preconditions:**
  - The Administrative Assistant or Head of Admin is logged in.
- **Postconditions:**
  - The vehicle details are displayed.

**Normal flow**

1. Administrative Assistant selects Vehicle.
   - 1.1 System displays the list of vehicles with their type, capacity and status.
2. Administrative Assistant filters the list by type or status.
   - 2.1 System displays the matching vehicles.
3. Administrative Assistant selects a vehicle.
   - 3.1 System displays the vehicle details and upcoming trips.

**Alternative courses**

- Not applicable

**Exceptions**

- 2.1 No vehicle matches the filter. The system displays a message that no record is found.

**Includes / extends**

- Not applicable

**Notes and issues**

- The vehicle status is Available, In Use, Under Maintenance or Inactive.

### UC007: Update Vehicle

- **Actor:** Administrative Assistant, Head of Admin, Driver
- **Description:** Allows the Administrative Assistant or Head of Admin to update the details of a vehicle. A driver can update the current mileage, fuel level and condition of a vehicle used for their task.
- **Priority / frequency:** Medium / Medium
- **Preconditions:**
  - The user is logged in.
  - The vehicle exists in the system.
- **Postconditions:**
  - The updated vehicle details are saved in the system.

**Normal flow**

1. Administrative Assistant selects a vehicle and clicks Update.
   - 1.1 System displays the vehicle details in an editable form.
2. Administrative Assistant edits the details.
3. Administrative Assistant clicks Save.
   - 3.1 System validates the entered details.
   - 3.2 System saves the changes.
   - 3.3 System displays a success message.

**Alternative courses**

- A1 Update by driver. At step 1, the driver opens a task in View Task (UC028) and clicks Update Vehicle. The system displays the mileage, fuel level and condition fields only. The driver enters the current mileage, fuel level and any condition issue, such as a warning light or damage, and clicks Save. The system saves the update with the driver's name, date and time. If a condition issue is entered, the system notifies the Administrative Assistant.

**Exceptions**

- 3.1 A required field is empty or the registration number already exists. The system displays an error message.
- A1 The new mileage is lower than the last recorded mileage. The system displays an error message.

**Includes / extends**

- Not applicable

**Notes and issues**

- A driver can only update a vehicle that is used for one of their tasks.
- A driver cannot change the registration number, type, capacity or required licence class.
- The mileage history helps the Administrative Assistant plan vehicle servicing.
- The vehicle status is changed in UC008, not in this use case.

### UC008: Update Vehicle Status

- **Actor:** Administrative Assistant, Head of Admin
- **Description:** Allows the Administrative Assistant or Head of Admin to mark a vehicle as Under Maintenance when it is sent to the workshop, and as Available when it returns.
- **Priority / frequency:** High / Medium
- **Preconditions:**
  - The Administrative Assistant or Head of Admin is logged in.
  - The vehicle is active.
- **Postconditions:**
  - The new vehicle status is saved.
  - A vehicle under maintenance cannot be selected by staff.

**Normal flow**

1. Administrative Assistant selects a vehicle and clicks Update Status.
   - 1.1 System displays the status options.
2. Administrative Assistant selects Under Maintenance, enters the workshop name and expected return date, and clicks Save.
   - 2.1 System checks whether the vehicle has upcoming trips.
   - 2.2 System saves the new status.
   - 2.3 System displays a success message.

**Alternative courses**

- A1 Vehicle returns. At step 2, the Administrative Assistant selects Available. The system saves the status and the vehicle can be selected again.

**Exceptions**

- 2.1 The vehicle has upcoming trips during the maintenance period. The system displays a warning and lists the affected trips.

**Includes / extends**

- Not applicable

**Notes and issues**

- The status In Use is set by the system when the vehicle has a confirmed trip at that time.

### UC009: Deactivate Vehicle

- **Actor:** Administrative Assistant, Head of Admin
- **Description:** Allows the Administrative Assistant or Head of Admin to deactivate a vehicle that is no longer used, such as a damaged or disposed vehicle.
- **Priority / frequency:** Low / Low
- **Preconditions:**
  - The Administrative Assistant or Head of Admin is logged in.
  - The vehicle is active.
- **Postconditions:**
  - The vehicle status is changed to Inactive.

**Normal flow**

1. Administrative Assistant selects a vehicle and clicks Deactivate.
   - 1.1 System checks whether the vehicle has upcoming trips.
   - 1.2 System displays a confirmation message.
2. Administrative Assistant confirms the action.
   - 2.1 System changes the vehicle status to Inactive.
   - 2.2 System displays a success message.

**Alternative courses**

- Not applicable

**Exceptions**

- 1.1 The vehicle has upcoming trips. The system displays a warning and does not deactivate the vehicle.

**Includes / extends**

- Not applicable

**Notes and issues**

- Inactive vehicles are not shown to staff.
- Vehicle records are not deleted, so past trips remain in the records.

### UC010: Submit Vehicle Request

- **Actor:** Staff (including Head of Department and Driver), Administrative Assistant
- **Description:** Allows a staff member to request a vehicle for a trip, with or without a driver. The system shows only vehicles that are available and suitable. The Administrative Assistant can also record an approved paper form request so that the vehicle is locked.
- **Priority / frequency:** High / High
- **Preconditions:**
  - The user is logged in.
- **Postconditions:**
  - The request is saved with the status Pending.
  - The vehicle is locked for the requested time period.
  - The Head of Department receives the request approval email (UC011).

**Normal flow**

1. Staff selects Submit Vehicle Request.
   - 1.1 System displays the request form.
2. Staff enters the departure and return dates and times, the number of passengers and whether a driver is needed.
   - 2.1 System finds vehicles that are not locked for that time period, not under maintenance and able to carry the number of passengers.
   - 2.2 System displays the list of available vehicles.
3. Staff chooses a vehicle.
4. Staff enters the destination name and full address, purpose, passenger names and phone number, chooses a waiting location and uploads supporting documents.
5. Staff clicks Submit.
   - 5.1 System validates the entered details.
   - 5.2 System saves the request with the status Pending.
   - 5.3 System locks the vehicle for the requested time period.
   - 5.4 System sends the request approval email to the Head of Department (UC011).
   - 5.5 System displays a success message.

**Alternative courses**

- A1 Other waiting location. At step 4, the staff member chooses Other and enters the address of the waiting location.
- A2 Short notice. At step 5, if the trip is less than two days away, the system displays a warning. The staff member confirms to continue.
- A3 Without driver. At step 2, the staff member chooses Without Driver. The request follows the same approval flow, but no driver is assigned.
- A4 Paper form request. At step 1, the Administrative Assistant selects Record Paper Form. The Administrative Assistant enters the details from the approved form BPTK/BPI/2025/PIN.5 and uploads the scanned form. The system saves the request with the status Approved and locks the vehicle. UC011 and UC012 are not performed because the request was already approved on paper.

**Exceptions**

- 2.2 No vehicle is available for the selected time. The system displays a message.
- 5.1 The trip is less than one day away. The system does not accept the request and informs the staff member to use the paper form BPTK/BPI/2025/PIN.5.
- 5.1 A required field is empty or the return time is before the departure time. The system displays an error message.

**Includes / extends**

- UC011 Receive Request Approval Email
- Extended by UC012 Receive Approval Reminder Email

**Notes and issues**

- The return date and time are mandatory.
- A request from a Head of Department is sent to the head of that Head of Department for approval.
- The vehicle lock is released if the request is rejected, cancelled or lapses.
- Paper form requests are recorded so that the vehicle is locked and clashes with online requests are prevented.

### UC011: Receive Request Approval Email

- **Actor:** Head of Department
- **Description:** Notifies the Head of Department by email and within the system when a staff member from the department submits a vehicle request (see Use Case UC010).
- **Priority / frequency:** High / High
- **Preconditions:**
  - A staff member has submitted a vehicle request (UC010).
  - The Head of Department has a registered email address.
- **Postconditions:**
  - The Head of Department receives an email and an in-system notification with the request details.

**Normal flow**

1. Staff submits a vehicle request (UC010).
   - 1.1 System identifies the Head of Department of the staff member's PTJ.
   - 1.2 System generates a notification with the request details and a link to the request.
   - 1.3 System sends the email to the Head of Department.
   - 1.4 System displays the notification in the system.
2. Head of Department clicks the link in the email.
   - 2.1 System displays the request details after the Head of Department logs in.

**Alternative courses**

- A1 Request from a Head of Department. At step 1.1, the system sends the email to the head of that Head of Department.

**Exceptions**

- 1.3 The email cannot be sent. The system still displays the in-system notification and records the failed email.

**Includes / extends**

- Not applicable

**Notes and issues**

- This use case is included in UC010 Submit Vehicle Request.
- The link in the email opens the request in Approve/Reject Request (UC015).

### UC012: Receive Approval Reminder Email

- **Actor:** Head of Department
- **Description:** Sends an automatic reminder to the Head of Department when a vehicle request is still waiting for approval one day after it was submitted (see Use Case UC010).
- **Priority / frequency:** Medium / Medium
- **Preconditions:**
  - A vehicle request has the status Pending.
  - The request was submitted more than one day ago.
- **Postconditions:**
  - The reminder is sent to the Head of Department and the reminder date is recorded.

**Normal flow**

1. Head of Department has not approved or rejected the request within one day.
   - 1.1 System checks pending requests every hour.
   - 1.2 System finds requests that have been pending for more than one day.
   - 1.3 System sends a reminder email and an in-system notification to the Head of Department.
   - 1.4 System records the date of the reminder.
2. Head of Department clicks the link in the reminder.
   - 2.1 System displays the request details (UC015).

**Alternative courses**

- A1 Request lapses. If the request is still pending two days after it was submitted, the system changes the status to Lapsed. The system then releases the vehicle lock and notifies the staff member and the Head of Department.

**Exceptions**

- 1.3 The email cannot be sent. The system still displays the in-system notification and records the failed email.

**Includes / extends**

- Not applicable

**Notes and issues**

- This use case extends UC010 Submit Vehicle Request when the request is still pending after one day.
- Only one reminder is sent for each request.

### UC013: View Request Status

- **Actor:** Staff (including Head of Department and Driver)
- **Description:** Allows a staff member to view the status of their own vehicle requests, together with the comments for any approval, rejection or cancellation.
- **Priority / frequency:** Medium / High
- **Preconditions:**
  - The staff member is logged in.
- **Postconditions:**
  - The status and details of the request are displayed.

**Normal flow**

1. Staff selects My Requests.
   - 1.1 System displays the list of the staff member's requests with their status.
2. Staff selects a request.
   - 2.1 System displays the request details, status history, assigned driver and vehicle, and comments.

**Alternative courses**

- Not applicable

**Exceptions**

- 1.1 The staff member has no requests. The system displays a message that no requests are found.

**Includes / extends**

- Not applicable

**Notes and issues**

- The request statuses are Pending, Approved, Driver Assigned, Confirmed, Rejected, Cancelled and Lapsed.
- The driver and vehicle are shown only after the assignment is Confirmed.

### UC014: Cancel Request

- **Actor:** Staff, Head of Department, Administrative Assistant
- **Description:** Allows a staff member to cancel their own request, or a Head of Department or the Administrative Assistant to cancel a request with a reason.
- **Priority / frequency:** High / Medium
- **Preconditions:**
  - The user is logged in.
  - The request has not been completed and has not already been cancelled, rejected or lapsed.
- **Postconditions:**
  - The request status is changed to Cancelled.
  - The vehicle lock is released and any assigned driver is freed.

**Normal flow**

1. User selects a request and clicks Cancel.
   - 1.1 System displays the comment box for the reason (UC016).
2. User enters the reason and clicks Confirm.
   - 2.1 System changes the request status to Cancelled.
   - 2.2 System releases the vehicle lock.
   - 2.3 System removes any assigned driver from the task.
   - 2.4 System sends in-system and email notifications to the staff member, the Head of Department, the Administrative Assistant and any assigned driver.
   - 2.5 System displays a success message.

**Alternative courses**

- Not applicable

**Exceptions**

- 2.1 The reason is empty. The system displays an error message.
- 2.1 The trip has already started. The system does not allow the cancellation.

**Includes / extends**

- UC016 Add Comment

**Notes and issues**

- The reason is shown to the staff member in View Request Status (UC013).
- Cancelled requests are kept in the system and are not deleted.

### UC015: Approve/Reject Request

- **Actor:** Head of Department
- **Description:** Allows the Head of Department to approve or reject a vehicle request from their department within two days.
- **Priority / frequency:** High / High
- **Preconditions:**
  - The Head of Department is logged in.
  - The request has the status Pending.
- **Postconditions:**
  - The request status is changed to Approved or Rejected.
  - If the request is rejected, the vehicle lock is released.
  - The staff member is notified. If the request is approved, the Administrative Assistant is also notified.

**Normal flow**

1. Head of Department selects Pending Requests.
   - 1.1 System displays the pending requests from the department.
2. Head of Department selects a request.
   - 2.1 System displays the request details and supporting documents.
3. Head of Department clicks Approve.
   - 3.1 System displays the comment box (UC016).
4. Head of Department clicks Confirm.
   - 4.1 System changes the status to Approved.
   - 4.2 System sends in-system and email notifications to the staff member and the Administrative Assistant.
   - 4.3 System displays a success message.

**Alternative courses**

- A1 Reject request. At step 3, the Head of Department clicks Reject and enters the reason in the comment box (UC016). The system changes the status to Rejected and releases the vehicle lock. The system then sends in-system and email notifications to the staff member with the reason.

**Exceptions**

- 1.1 There are no pending requests. The system displays a message.
- A1 The reason for rejection is empty. The system displays an error message.

**Includes / extends**

- UC016 Add Comment

**Notes and issues**

- The comment is optional for approval and mandatory for rejection.
- If the request is not approved within two days, the system changes the status to Lapsed (UC012).

### UC016: Add Comment

- **Actor:** Staff, Head of Department, Administrative Assistant, Head of Admin
- **Description:** Allows the user to enter a comment or reason when cancelling a request, approving or rejecting a request, or approving or rejecting a schedule change request.
- **Priority / frequency:** Medium / High
- **Preconditions:**
  - The user is logged in.
  - The user is performing UC014, UC015 or UC030.
- **Postconditions:**
  - The comment is saved and shown to the staff member or driver.

**Normal flow**

1. User performs Cancel Request (UC014), Approve/Reject Request (UC015) or Approve/Reject Schedule Change (UC030).
   - 1.1 System displays a comment box.
2. User enters the comment and clicks Confirm.
   - 2.1 System saves the comment with the user's name, date and time.
   - 2.2 System shows the comment in the request details.

**Alternative courses**

- Not applicable

**Exceptions**

- 2.1 The comment is empty for a rejection or cancellation. The system displays an error message.

**Includes / extends**

- Not applicable

**Notes and issues**

- This use case is included in UC014 Cancel Request, UC015 Approve/Reject Request and UC030 Approve/Reject Schedule Change.
- The comment is mandatory for rejection and cancellation. It is optional for approval.
- Comments cannot be edited or deleted after they are saved.

### UC017: Assign Driver

- **Actor:** Administrative Assistant, Head of Admin
- **Description:** Allows the Administrative Assistant to assign a driver and vehicle to an approved request. A driver can be assigned separately for the outbound trip and the return trip.
- **Priority / frequency:** High / High
- **Preconditions:**
  - The Administrative Assistant is logged in.
  - The request has the status Approved and needs a driver.
- **Postconditions:**
  - The driver assignment is saved with the status Driver Assigned.
  - The Head of Admin is notified to approve the assignment.

**Normal flow**

1. Administrative Assistant selects an approved request and clicks Assign Driver.
   - 1.1 System displays the request details.
   - 1.2 System displays the recommended drivers for the outbound trip (UC018).
2. Administrative Assistant selects a driver for the outbound trip.
   - 2.1 System checks that the driver has no clash at that time.
3. Administrative Assistant chooses whether the return trip uses the same driver and vehicle.
   - 3.1 If not, the system displays the recommended drivers for the return trip (UC018).
4. Administrative Assistant selects a driver and vehicle for the return trip and clicks Save.
   - 4.1 System saves the assignment with the status Driver Assigned.
   - 4.2 System sends in-system and email notifications to the Head of Admin.
   - 4.3 System displays a success message.

**Alternative courses**

- A1 Choose another driver. At step 2 or step 4, the Administrative Assistant selects a driver who is not the top recommendation. The system accepts the choice if the driver has the right licence and no clash.
- A2 Vendor bus. At step 2, if no driver with a PSV licence is available, the Administrative Assistant selects Vendor and enters the vendor name, bus registration number and vendor driver name. The system saves the assignment and sends it to the Head of Admin.

**Exceptions**

- 1.2 No suitable driver is available. The system displays a message. The Administrative Assistant can use a vendor bus (A2) or cancel the request with a reason (UC014).
- 2.1 The selected driver has a clash. The system displays a warning and does not allow the selection.
- 2.1 The selected driver is marked unavailable on the trip date (UC030). The system displays a warning.

**Includes / extends**

- UC018 View Driver Recommendation

**Notes and issues**

- The final choice of driver is made by the Administrative Assistant, not the system.
- The vendor rental process, such as quotation and payment, is handled outside DSMS.

### UC018: View Driver Recommendation

- **Actor:** Administrative Assistant, Head of Admin
- **Description:** Shows a ranked list of suitable drivers for a trip based on licence class, availability and a weighted score. This use case is included in Assign Driver.
- **Priority / frequency:** High / High
- **Preconditions:**
  - The Administrative Assistant is assigning or replacing a driver.
- **Postconditions:**
  - A ranked list of suitable drivers is displayed.

**Normal flow**

1. Administrative Assistant opens the driver selection for a trip.
   - 1.1 System filters drivers who hold the licence class needed for the vehicle.
   - 1.2 System removes drivers who already have a task during the trip time.
   - 1.3 System removes drivers who are marked unavailable on the trip date (UC030).
   - 1.4 System counts each driver's tasks this month and weekend tasks this month, and calculates their experience from the date they joined the Vehicle Section.
   - 1.5 System calculates a weighted score for each driver. The number of tasks this month has the highest weight.
   - 1.6 System ranks the drivers from the highest score to the lowest.
   - 1.7 System flags any driver who is on welfare duty on the trip date.
   - 1.8 System displays the ranked list with each driver's task counts and score.

**Alternative courses**

- Not applicable

**Exceptions**

- 1.3 No driver passes the licence, clash and availability checks. The system displays a message that no suitable driver is available.

**Includes / extends**

- Not applicable

**Notes and issues**

- This use case is included in UC017 Assign Driver.
- Drivers with fewer tasks this month get a higher score.
- Weekend tasks are tasks on Saturday or Sunday.
- Internal tasks and welfare duty are counted in the task count.

### UC019: Approve/Reject Driver Assignment

- **Actor:** Head of Admin
- **Description:** Allows the Head of Admin to approve or reject a driver assignment after checking that the driver is not on leave.
- **Priority / frequency:** High / High
- **Preconditions:**
  - The Head of Admin is logged in.
  - The assignment has the status Driver Assigned.
- **Postconditions:**
  - The assignment status is changed to Confirmed, or back to Approved if rejected.
  - The driver, the staff member or the Administrative Assistant is notified.

**Normal flow**

1. Head of Admin selects Pending Assignments.
   - 1.1 System displays the assignments waiting for approval.
2. Head of Admin selects an assignment.
   - 2.1 System displays the request, driver and vehicle details.
3. Head of Admin clicks Approve.
   - 3.1 System changes the status to Confirmed.
   - 3.2 System sends in-system and email notifications to the assigned driver.
   - 3.3 System sends in-system and email notifications to the staff member with the driver and vehicle details.
   - 3.4 System displays a success message.

**Alternative courses**

- A1 Reject assignment. At step 3, the Head of Admin clicks Reject and enters a reason, such as the driver being on leave. The system removes the driver from the task and changes the request status back to Approved. The system records the rejection with the reason and notifies the Administrative Assistant to assign another driver (UC017).
- A2 Request without driver. At step 2, the system shows only the vehicle details. The Head of Admin approves the use of the vehicle and the staff member is notified.

**Exceptions**

- 1.1 There are no pending assignments. The system displays a message.
- A1 The reason is empty. The system displays an error message.

**Includes / extends**

- Not applicable

**Notes and issues**

- Driver leave is kept in a separate UIS system, so the Head of Admin checks it manually before approving.

### UC020: Replace Driver

- **Actor:** Administrative Assistant, Head of Admin
- **Description:** Allows the Administrative Assistant to replace the driver of a confirmed task with another driver and record the reason.
- **Priority / frequency:** High / Medium
- **Preconditions:**
  - The Administrative Assistant is logged in.
  - The task has the status Confirmed and has not started.
- **Postconditions:**
  - The new driver is assigned and the replacement is recorded with a reason.
  - The task is sent to the Head of Admin for approval.

**Normal flow**

1. Administrative Assistant selects a confirmed task and clicks Replace Driver.
   - 1.1 System displays the recommended drivers for the task time (UC018).
2. Administrative Assistant selects the new driver and enters the reason for the replacement.
3. Administrative Assistant clicks Save.
   - 3.1 System saves the new driver and records the replacement with the reason and date.
   - 3.2 System changes the status to Driver Assigned for approval by the Head of Admin.
   - 3.3 System sends notifications to the previous driver, the new driver and the Head of Admin.
   - 3.4 System displays a success message.

**Alternative courses**

- A1 Replacement from a schedule change request. When the Head of Admin approves a schedule change request for a task (UC030), the system opens this use case with the reason Driver Request. Because the Head of Admin performs the replacement, the task is Confirmed directly.

**Exceptions**

- 1.1 No other driver is available. The system displays a message.
- 3.1 The reason is empty. The system displays an error message.

**Includes / extends**

- Not applicable

**Notes and issues**

- This use case extends UC030 Approve/Reject Schedule Change.
- Every replacement is kept in the task history so the Vehicle Section can see how often drivers are replaced and why.

### UC021: Add Internal Task

- **Actor:** Administrative Assistant, Head of Admin
- **Description:** Allows the Administrative Assistant to add an internal task such as a workshop run, refuelling, test drive or document delivery.
- **Priority / frequency:** Medium / Medium
- **Preconditions:**
  - The Administrative Assistant is logged in.
- **Postconditions:**
  - The internal task is saved and counted in the driver's workload.
  - The driver is notified.

**Normal flow**

1. Administrative Assistant selects Internal Task and clicks Add Task.
   - 1.1 System displays the internal task form.
2. Administrative Assistant selects the task type and enters the date, start and end time, vehicle, driver and description.
   - 2.1 System checks that the driver and vehicle have no clash at that time.
3. Administrative Assistant clicks Save.
   - 3.1 System saves the internal task.
   - 3.2 System sends in-system and email notifications to the driver.
   - 3.3 System displays a success message.

**Alternative courses**

- Not applicable

**Exceptions**

- 2.1 The driver or vehicle has a clash. The system displays a warning.
- 3.1 A required field is empty. The system displays an error message.

**Includes / extends**

- Not applicable

**Notes and issues**

- Internal tasks are counted in each driver's task count for the driver recommendation (UC018) and the monthly reports (UC031).

### UC022: View Internal Task

- **Actor:** Administrative Assistant, Head of Admin
- **Description:** Allows the Administrative Assistant to view the list of internal tasks and their details.
- **Priority / frequency:** Medium / Medium
- **Preconditions:**
  - The Administrative Assistant is logged in.
- **Postconditions:**
  - The internal task details are displayed.

**Normal flow**

1. Administrative Assistant selects Internal Task.
   - 1.1 System displays the list of internal tasks by date.
2. Administrative Assistant filters the list by date, driver or task type.
   - 2.1 System displays the matching tasks.
3. Administrative Assistant selects a task.
   - 3.1 System displays the task details.

**Alternative courses**

- Not applicable

**Exceptions**

- 2.1 No task matches the filter. The system displays a message that no record is found.

**Includes / extends**

- Not applicable

**Notes and issues**

- Internal tasks are also shown in the schedule calendar (UC027).

### UC023: Update Internal Task

- **Actor:** Administrative Assistant, Head of Admin
- **Description:** Allows the Administrative Assistant to update the details of an internal task.
- **Priority / frequency:** Medium / Low
- **Preconditions:**
  - The Administrative Assistant is logged in.
  - The internal task has not started.
- **Postconditions:**
  - The updated internal task is saved.
  - The driver is notified of the change.

**Normal flow**

1. Administrative Assistant selects an internal task and clicks Update.
   - 1.1 System displays the task details in an editable form.
2. Administrative Assistant edits the details.
   - 2.1 System checks that the driver and vehicle have no clash at the new time.
3. Administrative Assistant clicks Save.
   - 3.1 System saves the changes.
   - 3.2 System sends in-system and email notifications to the driver.
   - 3.3 System displays a success message.

**Alternative courses**

- Not applicable

**Exceptions**

- 2.1 The driver or vehicle has a clash. The system displays a warning.

**Includes / extends**

- Not applicable

**Notes and issues**

- If the driver is changed, both the previous driver and the new driver are notified.

### UC024: Delete Internal Task

- **Actor:** Administrative Assistant, Head of Admin
- **Description:** Allows the Administrative Assistant to delete an internal task that is no longer needed.
- **Priority / frequency:** Medium / Low
- **Preconditions:**
  - The Administrative Assistant is logged in.
  - The internal task has not started.
- **Postconditions:**
  - The internal task is deleted.
  - The driver is notified.

**Normal flow**

1. Administrative Assistant selects an internal task and clicks Delete.
   - 1.1 System displays a confirmation message.
2. Administrative Assistant confirms the action.
   - 2.1 System deletes the internal task.
   - 2.2 System releases the driver and vehicle for that time.
   - 2.3 System sends in-system and email notifications to the driver.
   - 2.4 System displays a success message.

**Alternative courses**

- Not applicable

**Exceptions**

- 1.1 The internal task has already started. The system does not allow the deletion.

**Includes / extends**

- Not applicable

**Notes and issues**

- Deleted internal tasks are not counted in the driver's workload.

### UC025: Set Welfare Duty

- **Actor:** Administrative Assistant, Head of Admin
- **Description:** Allows the Administrative Assistant to set the driver on 24-hour welfare duty for each day of a month.
- **Priority / frequency:** Medium / Low
- **Preconditions:**
  - The Administrative Assistant is logged in.
- **Postconditions:**
  - The welfare duty driver is saved for each selected day and shown in the calendar.
  - The drivers are notified.

**Normal flow**

1. Administrative Assistant selects Welfare Duty and chooses a month.
   - 1.1 System displays the days of the month.
2. Administrative Assistant selects a driver for each day.
3. Administrative Assistant clicks Save.
   - 3.1 System saves the welfare duty for each day.
   - 3.2 System shows the welfare duty in the schedule calendar.
   - 3.3 System sends notifications to the drivers.
   - 3.4 System displays a success message.

**Alternative courses**

- Not applicable

**Exceptions**

- 3.1 A day has no driver selected. The system displays a warning.
- 3.1 The selected driver is marked unavailable on that day (UC030). The system displays a warning.

**Includes / extends**

- Not applicable

**Notes and issues**

- Welfare duty runs for 24 hours, from 12 am to 12 am.
- Welfare duty is counted as a task in the driver's workload.

### UC026: Update Welfare Duty

- **Actor:** Administrative Assistant, Head of Admin
- **Description:** Allows the Administrative Assistant to change the welfare duty driver for a day, such as when the driver is sick.
- **Priority / frequency:** Medium / Low
- **Preconditions:**
  - The Administrative Assistant is logged in.
  - Welfare duty has been set for the selected day.
- **Postconditions:**
  - The new welfare duty driver is saved and the change is recorded with a reason.
  - Both drivers are notified.

**Normal flow**

1. Administrative Assistant selects a day in Welfare Duty and clicks Update.
   - 1.1 System displays the current welfare duty driver.
2. Administrative Assistant selects the replacement driver and enters the reason.
3. Administrative Assistant clicks Save.
   - 3.1 System saves the new driver and records the change with the reason.
   - 3.2 System sends notifications to the previous driver and the new driver.
   - 3.3 System displays a success message.

**Alternative courses**

- Not applicable

**Exceptions**

- 3.1 The reason is empty. The system displays an error message.

**Includes / extends**

- Not applicable

**Notes and issues**

- This replaces the PEMANDU GANTI note used in the current spreadsheet.

### UC027: View Schedule Calendar

- **Actor:** Administrative Assistant, Head of Admin
- **Description:** Allows the Administrative Assistant to view all trips, internal tasks and welfare duty in one calendar.
- **Priority / frequency:** High / High
- **Preconditions:**
  - The Administrative Assistant is logged in.
- **Postconditions:**
  - The schedule is displayed.

**Normal flow**

1. Administrative Assistant selects Schedule Calendar.
   - 1.1 System displays the calendar for the current week with all trips, internal tasks, welfare duty and unavailable drivers.
2. Administrative Assistant changes the view to day, week or month, or filters by driver or vehicle.
   - 2.1 System updates the calendar.
3. Administrative Assistant selects an item in the calendar.
   - 3.1 System displays the details of the trip or task.

**Alternative courses**

- Not applicable

**Exceptions**

- 2.1 There are no tasks in the selected period. The system displays an empty calendar.

**Includes / extends**

- Not applicable

**Notes and issues**

- This view replaces the monthly spreadsheet used by the Vehicle Section.

### UC028: View Task

- **Actor:** Driver
- **Description:** Allows a driver to view their own tasks, including trips, internal tasks and welfare duty, with full task details.
- **Priority / frequency:** High / High
- **Preconditions:**
  - The driver is logged in.
- **Postconditions:**
  - The driver's tasks and task details are displayed.

**Normal flow**

1. Driver selects My Tasks.
   - 1.1 System displays the driver's upcoming tasks in date order.
2. Driver selects a task.
   - 2.1 System displays the task details, which are the date, time, task type, vehicle, full destination address, waiting location, purpose, officer name and phone number.

**Alternative courses**

- A1 View past tasks. At step 1, the driver selects Past Tasks. The system displays the driver's completed tasks.
- A2 Request schedule change. At step 2, the driver clicks Request Schedule Change for the task (UC029).

**Exceptions**

- 1.1 The driver has no upcoming tasks. The system displays a message.

**Includes / extends**

- Not applicable

**Notes and issues**

- Drivers do not confirm tasks or update trip status in the system.
- The page is designed for smartphone screens.

### UC029: Request Schedule Change

- **Actor:** Driver
- **Description:** Allows a driver to inform the Vehicle Section in advance of dates they cannot work, such as weekends, or to ask to be withdrawn from a task that has been assigned to them.
- **Priority / frequency:** Medium / Medium
- **Preconditions:**
  - The driver is logged in.
- **Postconditions:**
  - The request is saved with the status Pending.
  - The Head of Admin is notified.

**Normal flow**

1. Driver selects Request Schedule Change.
   - 1.1 System displays the request form.
2. Driver selects the request type Unavailable Date and chooses the date or dates.
   - 2.1 System displays the driver's tasks on the selected dates, if any.
3. Driver enters the reason and clicks Submit.
   - 3.1 System validates the entered details.
   - 3.2 System saves the request with the status Pending.
   - 3.3 System sends in-system and email notifications to the Head of Admin.
   - 3.4 System displays a success message.

**Alternative courses**

- A1 Withdraw from task. At step 2, the driver selects the request type Withdraw from Task and chooses an assigned task. The driver can also start this request from View Task (UC028).

**Exceptions**

- 2.1 The selected date has already passed. The system displays an error message.
- 3.1 The reason is empty. The system displays an error message.
- 3.1 A pending request already exists for the same date or task. The system displays a message.

**Includes / extends**

- Not applicable

**Notes and issues**

- The driver cannot cancel a task directly. The task stays with the driver until the request is approved.
- A driver who does not inform the Vehicle Section in advance can still be assigned weekend tasks.

### UC030: Approve/Reject Schedule Change

- **Actor:** Head of Admin
- **Description:** Allows the Head of Admin to approve or reject a schedule change request from a driver.
- **Priority / frequency:** Medium / Medium
- **Preconditions:**
  - The Head of Admin is logged in.
  - The schedule change request has the status Pending.
- **Postconditions:**
  - The request status is changed to Approved or Rejected.
  - If approved, the driver is marked unavailable on the selected dates or withdrawn from the task.
  - The driver is notified.

**Normal flow**

1. Head of Admin selects Schedule Change Requests.
   - 1.1 System displays the pending requests.
2. Head of Admin selects a request.
   - 2.1 System displays the request details and the driver's tasks on the selected dates.
3. Head of Admin clicks Approve.
   - 3.1 System displays the comment box (UC016).
4. Head of Admin clicks Confirm.
   - 4.1 System changes the request status to Approved.
   - 4.2 System marks the driver as unavailable on the selected dates.
   - 4.3 System sends in-system and email notifications to the driver and the Administrative Assistant.
   - 4.4 System displays a success message.

**Alternative courses**

- A1 Reject request. At step 3, the Head of Admin clicks Reject and enters the reason in the comment box (UC016). The system changes the status to Rejected and notifies the driver with the reason. The task stays with the driver.
- A2 Driver has tasks. At step 4, if the request is to withdraw from a task, or the driver already has tasks on the selected dates, the system opens Replace Driver (UC020) for each affected task.

**Exceptions**

- 1.1 There are no pending requests. The system displays a message.
- A1 The reason for rejection is empty. The system displays an error message.

**Includes / extends**

- UC016 Add Comment
- Extended by UC020 Replace Driver

**Notes and issues**

- Drivers marked unavailable are not shown in the driver recommendation (UC018) for those dates.

### UC031: Generate Report

- **Actor:** Administrative Assistant, Head of Admin
- **Description:** Allows the Administrative Assistant to generate monthly reports on driver workload, driver replacements and vehicle usage.
- **Priority / frequency:** Medium / Low
- **Preconditions:**
  - The Administrative Assistant is logged in.
- **Postconditions:**
  - The selected report is displayed.

**Normal flow**

1. Administrative Assistant selects Generate Report.
   - 1.1 System displays the report options.
2. Administrative Assistant selects the report type and the month.
3. Administrative Assistant clicks Generate.
   - 3.1 System calculates the data for the selected month.
   - 3.2 System displays the report.

**Alternative courses**

- A1 Export report. At step 3, the Administrative Assistant can export the report (UC032).

**Exceptions**

- 3.1 There is no data for the selected month. The system displays a message.

**Includes / extends**

- Not applicable

**Notes and issues**

- The report types are tasks per driver, weekend tasks per driver, welfare duty days per driver, driver replacements and vehicle usage.

### UC032: Export Report

- **Actor:** Administrative Assistant, Head of Admin
- **Description:** Allows the Administrative Assistant to print a report or export it as a PDF or Excel file. This use case extends Generate Report.
- **Priority / frequency:** Low / Low
- **Preconditions:**
  - The Administrative Assistant is logged in.
  - A report has been generated.
- **Postconditions:**
  - The report is printed or saved as a file.

**Normal flow**

1. Administrative Assistant clicks Export and selects PDF, Excel or Print.
   - 1.1 System creates the file in the selected format.
   - 1.2 System downloads the file or opens the print window.

**Alternative courses**

- Not applicable

**Exceptions**

- 1.1 The file cannot be created. The system displays an error message.

**Includes / extends**

- Not applicable

**Notes and issues**

- This use case extends UC031 Generate Report.

## 4. Other Non-functional Requirements

### Performance Requirements

The performance of DSMS is important so that staff, approvers and the Vehicle Section can manage requests and driver tasks without delay.

- The system shall support at least 50 concurrent users without noticeable delay.

- The system shall display the vehicle availability result within 3 seconds after the staff member enters the trip dates and times.

- The system shall display the driver recommendation within 3 seconds after the Administrative Assistant selects a request.

- The system shall load the schedule calendar and task list within 5 seconds after a successful login.

- The system shall send an in-system and email notification within 1 minute after a request or task status changes.

- The system shall check pending requests at least once every hour. It shall send a reminder email to the Head of Department when a request is still pending after one day. A request that is not approved within two days shall change to Lapsed and release the vehicle.

- The system shall generate the monthly report within 10 seconds for one month of records.

- The system shall be available 24 hours a day except during scheduled maintenance.

### Safety and Security Requirements

DSMS must protect staff and driver data and prevent unauthorised changes to requests and schedules.

- The system shall require a username and password for login. Only active staff accounts from the UIS staff system can access the system.

- The system shall apply role-based access control for Staff, Driver, Head of Department, Administrative Assistant and Head of Admin. Drivers can only view their own tasks and profile, and update only the vehicles used in their tasks. A Head of Department can only approve requests from their PTJ. Only the Administrative Assistant and Head of Admin can assign or replace drivers. Only the Head of Admin can approve driver assignments and schedule change requests.

- The system shall not store or display passwords in plain text.

- The system shall log out users automatically after 15 minutes of inactivity.

- The system shall block login for 15 minutes after 5 failed login attempts.

- The system shall use HTTPS for all pages to protect data sent between the browser and the server.

- The system shall use prepared statements for all database queries to prevent SQL injection.

- The system shall accept supporting documents in PDF, JPG and PNG format only, with a maximum size of 5 MB per file.

- The system shall prevent two requests or tasks from holding the same vehicle or the same driver during the same time period, even when they are submitted at the same time.

- The system shall record every approval, rejection, cancellation, driver assignment and driver replacement with the user, date, time and reason. These records cannot be edited or deleted.

### Software Quality Attributes

DSMS shall have the following quality attributes to meet the needs of the users and the developer.

- **Usability.** The system shall be easy to use for users with basic computer knowledge. The driver task page shall be simple and readable on a smartphone because drivers have different levels of skill with technology.

- **Reliability.** The system shall store requests, assignments and replacements without data loss. Vehicle and driver availability shall always match the latest records.

- **Availability.** The system shall be accessible 24 hours a day so that staff can submit requests and drivers can view tasks at any time.

- **Portability.** The system shall run on common web browsers such as Google Chrome, Microsoft Edge, Mozilla Firefox and Safari. Users shall not be required to use only one browser.

- **Responsiveness.** The pages shall adjust to desktop, tablet and smartphone screens.

- **Maintainability.** The system shall be developed in separate modules so that a change in one module does not affect other modules.

- **Scalability.** The Administrative Assistant shall be able to add new drivers and vehicles without changes to the source code.

- **Testability.** Each module shall support unit testing and integration testing.

- **Adaptability.** The system shall allow future integration with e-Kenderaan and the UIS leave system without major changes to the system.

- **Security and Robustness.** The system shall handle invalid input without crashing or exposing data. It shall display a clear error message and keep the existing records unchanged.

## 5. Other Requirements

### Legal and Compliance Requirements

- The system must comply with the Personal Data Protection Act 2010 (PDPA) to protect the personal data of staff and drivers, such as names, staff IDs, phone numbers and licence details.

- The system shall follow the official vehicle request rules of UIS. A request should be made at least two days before the trip. The system shall warn the staff member when the trip is less than two days away and shall not accept a request made less than one day before the trip. Such requests shall use the paper form BPTK/BPI/2025/PIN.5, and the Administrative Assistant shall record the approved form in DSMS.

- One request is for one trip only. Requests for student activities must be made by the accompanying staff or lecturer.

- The request form in the system shall contain the same information as the official vehicle booking form BPTK/BPI/2025/PIN.5 so that the records stay consistent with the current procedure.

- Records of approvals, rejections, cancellations, driver assignments and driver replacements shall be kept for audit purposes for at least five years.

- Personal data shall only be used for vehicle requests and driver scheduling. It shall not be shared outside the Vehicle Section and the related approvers.

- The system shall be developed using software that is free to use under its licence, such as PHP, MySQL and XAMPP.

### Backup and Recovery

- Uploaded supporting documents shall be included in the daily backup.

- Backup files shall be kept for at least 30 days and stored in a secure location with restricted access.

- The Administrative Assistant or the system administrator shall be able to run a manual backup before any system update.

- A restore process shall be available to recover the system and database within 24 hours after a system failure, data corruption or accidental deletion.

- Scheduled maintenance shall be carried out outside office hours so that staff and drivers are not affected during working time.

## Appendix A. Class diagram (for the database design)

Activity diagrams AD001–AD032 and system sequence diagrams SSD001–SSD032 are images in the SRS docx and follow the use case flows above.

- **User** («from UIS staff system»): staffID: String, name: String, email: String, phone: String, ptj: String, role: String | login(): Boolean
- **Driver**: driverID: String, staffID: String, licenceClasses: String, licenceExpiry: Date, dateJoined: Date, status: String | addDriver(): void, viewProfile(): Driver, updateProfile(): void, deactivate(): void
- **Vehicle**: vehicleID: String, regNo: String, type: String, capacity: int, requiredLicence: String, status: String | addVehicle(): void, updateVehicle(): void, updateStatus(): void, deactivate(): void
- **VehicleUsageLog**: logID: String, vehicleID: String, driverID: String, mileage: int, fuelLevel: String, conditionIssue: String, recordedAt: DateTime | recordUsage(): void
- **VehicleRequest**: requestID: String, staffID: String, vehicleID: String, departDateTime: DateTime, returnDateTime: DateTime, destination: String, address: String, purpose: String, noOfPassengers: int, waitingLocation: String, needDriver: Boolean, requestType: String, status: String, submittedAt: DateTime | submitRequest(): void, cancelRequest(): void, approve(): void, reject(): void, sendReminder(): void, lapse(): void
- **SupportingDocument**: docID: String, requestID: String, fileName: String, filePath: String | upload(): void
- **Comment**: commentID: String, requestID: String, changeID: String, userID: String, commentText: String, createdAt: DateTime | addComment(): void
- **Assignment**: assignmentID: String, requestID: String, tripLeg: String, driverID: String, vehicleID: String, vendorName: String, vendorBusRegNo: String, vendorDriverName: String, status: String, assignedBy: String, approvedBy: String | assignDriver(): void, approve(): void, reject(): void
- **DriverReplacement**: replacementID: String, assignmentID: String, oldDriverID: String, newDriverID: String, reason: String, replacedBy: String, replacedAt: DateTime | replaceDriver(): void
- **InternalTask**: taskID: String, taskType: String, taskDate: Date, startTime: Time, endTime: Time, driverID: String, vehicleID: String, description: String | addTask(): void, updateTask(): void, deleteTask(): void
- **WelfareDuty**: dutyID: String, dutyDate: Date, driverID: String | setDuty(): void, updateDuty(): void
- **WelfareDutyChange**: dutyChangeID: String, dutyID: String, oldDriverID: String, newDriverID: String, reason: String, changedAt: DateTime
- **ScheduleChangeRequest**: changeID: String, driverID: String, changeType: String, dates: Date[], taskID: String, reason: String, status: String | submitChange(): void, approve(): void, reject(): void
- **Notification**: notificationID: String, userID: String, message: String, channel: String, sentAt: DateTime, isRead: Boolean | send(): void
- **Report**: reportID: String, reportType: String, month: String, generatedBy: String | generateReport(): void, exportReport(): void

**Relationships**

- User 1 —submits→ 0..* VehicleRequest
- User 1 —is a→ 0..1 Driver
- User 1 —receives→ 0..* Notification
- User 1 —generates→ 0..* Report
- VehicleRequest 1 —has→ 0..* SupportingDocument
- VehicleRequest 1 —has→ 0..* Comment
- VehicleRequest 1 —has→ 0..2 Assignment
- VehicleRequest 0..* —locks→ 1 Vehicle
- Assignment 0..* —assigned to→ 0..1 Driver
- Assignment 0..* —uses→ 1 Vehicle
- Assignment 1 —records→ 0..* DriverReplacement
- Driver 1 —performs→ 0..* InternalTask
- Vehicle 1 —used in→ 0..* InternalTask
- Driver 1 —covers→ 0..* WelfareDuty
- WelfareDuty 1 —records→ 0..* WelfareDutyChange
- Driver 1 —submits→ 0..* ScheduleChangeRequest
- ScheduleChangeRequest 1 —has→ 0..* Comment
- Vehicle 1 —has→ 0..* VehicleUsageLog
- Driver 1 —records→ 0..* VehicleUsageLog
