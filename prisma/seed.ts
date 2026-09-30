import { PrismaClient, Role, EmploymentType, AttendanceStatus, ApprovalStatus, CandidateStage, JobStatus, PayrollStatus, TicketStatus, TicketPriority, DocumentType, AssetStatus, CycleStatus } from "@prisma/client";
import bcrypt from "bcryptjs";

const prisma = new PrismaClient();
const DEMO_PASSWORD = "Passw0rd!";

function addDays(date: Date, days: number) {
  const d = new Date(date);
  d.setDate(d.getDate() + days);
  return d;
}

function daysAgo(n: number) {
  return addDays(new Date(), -n);
}

function atTime(date: Date, hh: number, mm: number) {
  const d = new Date(date);
  d.setHours(hh, mm, 0, 0);
  return d;
}

function randomBetween(min: number, max: number) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

/** A birthday `age` years ago, `offsetDays` from today — used to guarantee a couple of demo
 * entries land in "this month" for the dashboard's Birthdays widget regardless of seed date. */
function birthdayInDays(offsetDays: number, age: number) {
  const d = addDays(new Date(), offsetDays);
  d.setFullYear(d.getFullYear() - age);
  return d;
}

function randomBirthDate() {
  const age = randomBetween(24, 58);
  return new Date(new Date().getFullYear() - age, randomBetween(0, 11), randomBetween(1, 28));
}

/** A join date exactly `yearsAgo` years ago, same month as today — guarantees a demo
 * work-anniversary entry for the dashboard's Anniversaries widget. */
function anniversaryThisMonth(yearsAgo: number, day: number) {
  const now = new Date();
  return new Date(now.getFullYear() - yearsAgo, now.getMonth(), Math.min(day, 28));
}

async function hash(pw: string) {
  return bcrypt.hash(pw, 10);
}

async function main() {
  console.log("Seeding Verdant HR...");

  // ── Clean slate ────────────────────────────────────────────────
  await prisma.$transaction([
    prisma.notification.deleteMany(),
    prisma.auditLog.deleteMany(),
    prisma.ticketComment.deleteMany(),
    prisma.ticket.deleteMany(),
    prisma.assetAssignment.deleteMany(),
    prisma.asset.deleteMany(),
    prisma.document.deleteMany(),
    prisma.expenseClaim.deleteMany(),
    prisma.performanceReview.deleteMany(),
    prisma.goal.deleteMany(),
    prisma.reviewCycle.deleteMany(),
    prisma.interview.deleteMany(),
    prisma.candidate.deleteMany(),
    prisma.jobPosting.deleteMany(),
    prisma.payslip.deleteMany(),
    prisma.payrollRun.deleteMany(),
    prisma.salaryStructure.deleteMany(),
    prisma.holiday.deleteMany(),
    prisma.leaveRequest.deleteMany(),
    prisma.leaveBalance.deleteMany(),
    prisma.leaveType.deleteMany(),
    prisma.attendanceRegularization.deleteMany(),
    prisma.attendance.deleteMany(),
    prisma.shift.deleteMany(),
    prisma.fullFinalSettlement.deleteMany(),
    prisma.exitClearance.deleteMany(),
    prisma.employeeExit.deleteMany(),
    prisma.confirmation.deleteMany(),
    prisma.onboardingTask.deleteMany(),
    prisma.onboarding.deleteMany(),
    prisma.onboardingTaskTemplate.deleteMany(),
    prisma.letterTemplate.deleteMany(),
    prisma.statutoryConfig.deleteMany(),
    prisma.promotionTransfer.deleteMany(),
    prisma.employee.deleteMany(),
    prisma.user.deleteMany(),
    prisma.department.deleteMany(),
    prisma.branch.deleteMany(),
    prisma.companyPolicy.deleteMany(),
    prisma.company.deleteMany(),
  ]);

  const passwordHash = await hash(DEMO_PASSWORD);

  // ── Super admin (platform level, no company) ──────────────────
  await prisma.user.create({
    data: {
      email: "superadmin@verdanthr.io",
      passwordHash,
      role: Role.SUPER_ADMIN,
    },
  });

  // ── A second, smaller tenant, just to prove multi-tenancy ─────
  const otherCo = await prisma.company.create({
    data: {
      name: "Northwind Retail",
      slug: "northwind-retail",
      industry: "Retail",
      policy: { create: {} },
    },
  });
  await prisma.user.create({
    data: {
      email: "admin@northwindretail.com",
      passwordHash,
      role: Role.COMPANY_ADMIN,
      companyId: otherCo.id,
    },
  });

  // ── Primary demo tenant ─────────────────────────────────────────
  const company = await prisma.company.create({
    data: {
      name: "Meridian Works",
      slug: "meridian-works",
      industry: "Technology Services",
      policy: { create: {} },
    },
  });

  const [hq, blr] = await Promise.all([
    prisma.branch.create({ data: { companyId: company.id, name: "Pune HQ", city: "Pune", state: "Maharashtra", isHQ: true } }),
    prisma.branch.create({ data: { companyId: company.id, name: "Bengaluru Office", city: "Bengaluru", state: "Karnataka" } }),
  ]);

  const [engDept, peopleDept, salesDept, financeDept, designDept] = await Promise.all([
    prisma.department.create({ data: { companyId: company.id, name: "Engineering" } }),
    prisma.department.create({ data: { companyId: company.id, name: "People Operations" } }),
    prisma.department.create({ data: { companyId: company.id, name: "Sales" } }),
    prisma.department.create({ data: { companyId: company.id, name: "Finance" } }),
    prisma.department.create({ data: { companyId: company.id, name: "Design" } }),
  ]);

  const dayShift = await prisma.shift.create({
    data: { companyId: company.id, name: "General Shift", startTime: "09:30", endTime: "18:30", graceMinutes: 15 },
  });

  // ── Onboarding task templates ────────────────────────────────────
  await prisma.onboardingTaskTemplate.createMany({
    data: [
      { companyId: company.id, category: "HR", title: "Collect ID proof & signed offer acceptance", sortOrder: 1 },
      { companyId: company.id, category: "HR", title: "Initiate background verification (BGV)", sortOrder: 2 },
      { companyId: company.id, category: "HR", title: "Share employee handbook & policy acknowledgement", sortOrder: 3 },
      { companyId: company.id, category: "IT", title: "Create company email account", sortOrder: 1 },
      { companyId: company.id, category: "IT", title: "Allocate laptop and other assets", sortOrder: 2 },
      { companyId: company.id, category: "IT", title: "Provision system/application access", sortOrder: 3 },
      { companyId: company.id, category: "MANAGER", title: "Prepare workstation and welcome kit", sortOrder: 1 },
      { companyId: company.id, category: "MANAGER", title: "Schedule day-one team introduction", sortOrder: 2 },
    ],
  });

  // ── Letter templates (BRD 8.15 — template-based letter generation) ─
  await prisma.letterTemplate.createMany({
    data: [
      {
        companyId: company.id, type: "OFFER",
        subject: "Offer of Employment — {{employeeName}}",
        bodyHtml: "Dear {{employeeName}},\n\nWe are pleased to offer you the position of {{designation}} at {{companyName}}, effective {{dateOfJoining}}, with an annual CTC of {{ctc}}.\n\nWe look forward to having you on board.",
      },
      {
        companyId: company.id, type: "APPOINTMENT",
        subject: "Appointment Letter — {{employeeName}}",
        bodyHtml: "Dear {{employeeName}},\n\nThis confirms your appointment as {{designation}} in the {{department}} department at {{companyName}}, effective {{dateOfJoining}}.",
      },
      {
        companyId: company.id, type: "CONFIRMATION",
        subject: "Confirmation of Employment — {{employeeName}}",
        bodyHtml: "Dear {{employeeName}},\n\nWe are pleased to confirm your employment as {{designation}} at {{companyName}}, effective {{today}}, following successful completion of your probation period.",
      },
      {
        companyId: company.id, type: "PROMOTION",
        subject: "Promotion Letter — {{employeeName}}",
        bodyHtml: "Dear {{employeeName}},\n\nCongratulations! In recognition of your contributions, you have been promoted to {{designation}}, effective {{today}}.",
      },
      {
        companyId: company.id, type: "RELIEVING",
        subject: "Relieving Letter — {{employeeName}}",
        bodyHtml: "Dear {{employeeName}},\n\nThis is to confirm that you have been relieved from your role as {{designation}} at {{companyName}}, with your last working day being {{dateOfExit}}.\n\nWe wish you the best in your future endeavours.",
      },
      {
        companyId: company.id, type: "EXPERIENCE",
        subject: "Experience Letter — {{employeeName}}",
        bodyHtml: "This is to certify that {{employeeName}} worked at {{companyName}} as {{designation}} from {{dateOfJoining}} to {{dateOfExit}}. During this period, we found them to be sincere and hardworking.",
      },
    ],
  });

  const leaveTypeDefs = [
    { name: "Casual Leave", daysPerYear: 12, paid: true, carryForward: false },
    { name: "Sick Leave", daysPerYear: 8, paid: true, carryForward: false },
    { name: "Earned Leave", daysPerYear: 15, paid: true, carryForward: true },
    { name: "Unpaid Leave", daysPerYear: 0, paid: false, carryForward: false },
  ];
  const leaveTypes = await Promise.all(
    leaveTypeDefs.map((lt) => prisma.leaveType.create({ data: { companyId: company.id, ...lt } }))
  );
  const [casual, sick, earned] = leaveTypes;

  const year = new Date().getFullYear();
  await prisma.holiday.createMany({
    data: [
      { companyId: company.id, name: "Republic Day", date: new Date(year, 0, 26) },
      { companyId: company.id, name: "Independence Day", date: new Date(year, 7, 15) },
      { companyId: company.id, name: "Gandhi Jayanti", date: new Date(year, 9, 2) },
      { companyId: company.id, name: "Diwali", date: new Date(year, 10, 12) },
      { companyId: company.id, name: "Christmas", date: new Date(year, 11, 25) },
    ],
  });

  // ── Employees ───────────────────────────────────────────────────
  // Order matters: managers are created before their reports reference them.
  type SeedEmployee = {
    key: string;
    firstName: string;
    lastName: string;
    designation: string;
    dept: string;
    branch: string;
    doj: Date;
    managerKey?: string;
    account?: { email: string; role: Role };
    basic: number;
    dob?: Date;
  };

  const deptMap: Record<string, string> = {
    Engineering: engDept.id,
    People: peopleDept.id,
    Sales: salesDept.id,
    Finance: financeDept.id,
    Design: designDept.id,
  };
  const branchMap: Record<string, string> = { HQ: hq.id, BLR: blr.id };

  const seedEmployees: SeedEmployee[] = [
    { key: "admin", firstName: "Ananya", lastName: "Rao", designation: "Chief Executive Officer", dept: "People", branch: "HQ", doj: daysAgo(1200), account: { email: "admin@meridianworks.com", role: Role.COMPANY_ADMIN }, basic: 180000 },
    { key: "hr", firstName: "Kabir", lastName: "Malhotra", designation: "HR Business Partner", dept: "People", branch: "HQ", doj: daysAgo(900), managerKey: "admin", account: { email: "hr@meridianworks.com", role: Role.HR }, basic: 95000 },
    { key: "manager", firstName: "Priya", lastName: "Iyer", designation: "Engineering Manager", dept: "Engineering", branch: "HQ", doj: daysAgo(1100), managerKey: "admin", account: { email: "manager@meridianworks.com", role: Role.MANAGER }, basic: 140000 },
    { key: "employee", firstName: "Rohan", lastName: "Verma", designation: "Software Engineer II", dept: "Engineering", branch: "HQ", doj: daysAgo(420), managerKey: "manager", account: { email: "employee@meridianworks.com", role: Role.EMPLOYEE }, basic: 82000, dob: birthdayInDays(4, 29) },
    { key: "recruiter", firstName: "Simran", lastName: "Kaur", designation: "Talent Acquisition Specialist", dept: "People", branch: "HQ", doj: daysAgo(300), managerKey: "hr", account: { email: "recruiter@meridianworks.com", role: Role.RECRUITER }, basic: 62000 },
    { key: "payrollAdmin", firstName: "Manoj", lastName: "Pillai", designation: "Payroll Administrator", dept: "Finance", branch: "HQ", doj: daysAgo(500), managerKey: "admin", account: { email: "payroll@meridianworks.com", role: Role.PAYROLL_ADMIN }, basic: 78000 },
    { key: "finance", firstName: "Ritika", lastName: "Sethi", designation: "Finance Manager", dept: "Finance", branch: "HQ", doj: daysAgo(700), managerKey: "admin", account: { email: "finance@meridianworks.com", role: Role.FINANCE }, basic: 105000 },
    { key: "itAdmin", firstName: "Devansh", lastName: "Rathi", designation: "IT Systems Administrator", dept: "Engineering", branch: "HQ", doj: daysAgo(600), managerKey: "admin", account: { email: "it@meridianworks.com", role: Role.IT_ADMIN }, basic: 70000 },
    { key: "auditor", firstName: "Lakshmi", lastName: "Narayan", designation: "Internal Auditor", dept: "Finance", branch: "HQ", doj: daysAgo(400), managerKey: "admin", account: { email: "auditor@meridianworks.com", role: Role.AUDITOR }, basic: 75000 },
    { key: "management", firstName: "Vikas", lastName: "Oberoi", designation: "VP Operations", dept: "People", branch: "HQ", doj: daysAgo(1000), managerKey: "admin", account: { email: "management@meridianworks.com", role: Role.MANAGEMENT }, basic: 160000 },

    { key: "e1", firstName: "Sneha", lastName: "Kulkarni", designation: "Senior Software Engineer", dept: "Engineering", branch: "HQ", doj: daysAgo(760), managerKey: "manager", basic: 110000 },
    { key: "e2", firstName: "Arjun", lastName: "Nair", designation: "Software Engineer I", dept: "Engineering", branch: "BLR", doj: daysAgo(200), managerKey: "manager", basic: 68000 },
    { key: "e3", firstName: "Meera", lastName: "Pillai", designation: "QA Engineer", dept: "Engineering", branch: "HQ", doj: daysAgo(540), managerKey: "manager", basic: 72000 },
    { key: "e4", firstName: "Vikram", lastName: "Shah", designation: "DevOps Engineer", dept: "Engineering", branch: "BLR", doj: daysAgo(300), managerKey: "manager", basic: 95000 },
    { key: "e5", firstName: "Ishita", lastName: "Bose", designation: "Product Designer", dept: "Design", branch: "HQ", doj: daysAgo(610), managerKey: "admin", basic: 88000, dob: birthdayInDays(11, 33) },
    { key: "e6", firstName: "Farhan", lastName: "Ahmed", designation: "UX Researcher", dept: "Design", branch: "HQ", doj: anniversaryThisMonth(2, 14), managerKey: "e5", basic: 65000 },
    { key: "e7", firstName: "Divya", lastName: "Menon", designation: "Sales Manager", dept: "Sales", branch: "BLR", doj: daysAgo(980), managerKey: "admin", basic: 105000 },
    { key: "e8", firstName: "Karan", lastName: "Chopra", designation: "Account Executive", dept: "Sales", branch: "BLR", doj: daysAgo(260), managerKey: "e7", basic: 58000 },
    { key: "e9", firstName: "Neha", lastName: "Joshi", designation: "Finance Analyst", dept: "Finance", branch: "HQ", doj: daysAgo(500), managerKey: "admin", basic: 72000 },
    { key: "e10", firstName: "Aditya", lastName: "Kapoor", designation: "Recruiter", dept: "People", branch: "HQ", doj: daysAgo(180), managerKey: "hr", basic: 60000 },
    { key: "e11", firstName: "Ritu", lastName: "Desai", designation: "Support Engineer", dept: "Engineering", branch: "HQ", doj: daysAgo(90), managerKey: "manager", basic: 62000 },
  ];

  const employeeIdByKey: Record<string, string> = {};
  const userIdByKey: Record<string, string> = {};
  let codeCounter = 1001;

  for (const se of seedEmployees) {
    let userId: string | undefined;
    if (se.account) {
      const user = await prisma.user.create({
        data: { email: se.account.email, passwordHash, role: se.account.role, companyId: company.id },
      });
      userId = user.id;
      userIdByKey[se.key] = user.id;
    }

    const emp = await prisma.employee.create({
      data: {
        companyId: company.id,
        userId,
        employeeCode: `MW-${codeCounter++}`,
        firstName: se.firstName,
        lastName: se.lastName,
        email: se.account?.email ?? `${se.firstName}.${se.lastName}@meridianworks.com`.toLowerCase(),
        phone: `98${randomBetween(10000000, 99999999)}`,
        gender: randomBetween(0, 1) ? "MALE" : "FEMALE",
        designation: se.designation,
        employmentType: EmploymentType.FULL_TIME,
        dateOfBirth: se.dob ?? randomBirthDate(),
        dateOfJoining: se.doj,
        branchId: branchMap[se.branch],
        departmentId: deptMap[se.dept],
        managerId: se.managerKey ? employeeIdByKey[se.managerKey] : null,
        avatarColor: ["#1f4d3a", "#c1712f", "#2c6a8c", "#7a5a2f", "#5a3a7a"][randomBetween(0, 4)],
      },
    });
    employeeIdByKey[se.key] = emp.id;
  }

  const allEmployees = await prisma.employee.findMany({ where: { companyId: company.id } });

  // ── Salary structures ───────────────────────────────────────────
  for (const se of seedEmployees) {
    const basic = se.basic;
    await prisma.salaryStructure.create({
      data: {
        employeeId: employeeIdByKey[se.key],
        basic,
        hra: Math.round(basic * 0.4),
        conveyance: 1600,
        medicalAllowance: 1250,
        specialAllowance: Math.round(basic * 0.15),
        pfEmployee: Math.round(basic * 0.12),
        esi: basic < 21000 ? Math.round(basic * 0.0075) : 0,
        professionalTax: 200,
      },
    });
  }

  // ── Leave balances (current year, for everyone) ─────────────────
  for (const emp of allEmployees) {
    for (const lt of [casual, sick, earned]) {
      await prisma.leaveBalance.create({
        data: {
          employeeId: emp.id,
          leaveTypeId: lt.id,
          year,
          allocated: lt.daysPerYear,
          used: randomBetween(0, Math.min(4, lt.daysPerYear)),
        },
      });
    }
  }

  // ── Attendance: last 45 calendar days for every employee ────────
  const attendanceRows: {
    employeeId: string; date: Date; checkIn: Date | null; checkOut: Date | null;
    status: AttendanceStatus; workedMins: number; shiftId: string;
  }[] = [];

  for (const emp of allEmployees) {
    for (let i = 45; i >= 1; i--) {
      const date = daysAgo(i);
      const dow = date.getDay();
      if (dow === 0 || dow === 6) continue; // weekend

      const roll = Math.random();
      if (roll < 0.05) {
        attendanceRows.push({ employeeId: emp.id, date, checkIn: null, checkOut: null, status: AttendanceStatus.ABSENT, workedMins: 0, shiftId: dayShift.id });
        continue;
      }
      if (roll < 0.09) {
        attendanceRows.push({ employeeId: emp.id, date, checkIn: null, checkOut: null, status: AttendanceStatus.ON_LEAVE, workedMins: 0, shiftId: dayShift.id });
        continue;
      }

      const late = roll > 0.8;
      const checkIn = atTime(date, 9, late ? randomBetween(46, 75) % 60 : randomBetween(15, 45));
      if (late) checkIn.setHours(10, randomBetween(0, 20), 0, 0);
      const checkOut = atTime(date, 18, randomBetween(15, 55));
      const workedMins = Math.round((checkOut.getTime() - checkIn.getTime()) / 60000);

      attendanceRows.push({
        employeeId: emp.id,
        date,
        checkIn,
        checkOut,
        status: late ? AttendanceStatus.LATE : AttendanceStatus.PRESENT,
        workedMins,
        shiftId: dayShift.id,
      });
    }
  }
  // createMany in chunks
  for (let i = 0; i < attendanceRows.length; i += 500) {
    await prisma.attendance.createMany({ data: attendanceRows.slice(i, i + 500) });
  }

  // A couple of pending regularization requests
  await prisma.attendanceRegularization.createMany({
    data: [
      {
        employeeId: employeeIdByKey.employee,
        date: daysAgo(3),
        requestedCheckIn: atTime(daysAgo(3), 9, 40),
        requestedCheckOut: atTime(daysAgo(3), 19, 5),
        reason: "Forgot to check out — was on a client call past shift end.",
        status: ApprovalStatus.PENDING,
        approverId: employeeIdByKey.manager,
      },
      {
        employeeId: employeeIdByKey.e2,
        date: daysAgo(6),
        requestedCheckIn: atTime(daysAgo(6), 9, 35),
        requestedCheckOut: atTime(daysAgo(6), 18, 40),
        reason: "Biometric device was offline at the Bengaluru office that morning.",
        status: ApprovalStatus.PENDING,
        approverId: employeeIdByKey.manager,
      },
    ],
  });

  // ── Leave requests ──────────────────────────────────────────────
  await prisma.leaveRequest.createMany({
    data: [
      {
        employeeId: employeeIdByKey.employee,
        leaveTypeId: casual.id,
        startDate: addDays(new Date(), 5),
        endDate: addDays(new Date(), 6),
        days: 2,
        reason: "Family function out of town.",
        status: ApprovalStatus.PENDING,
        approverId: employeeIdByKey.manager,
      },
      {
        employeeId: employeeIdByKey.e3,
        leaveTypeId: sick.id,
        startDate: daysAgo(10),
        endDate: daysAgo(10),
        days: 1,
        reason: "Fever, saw a doctor.",
        status: ApprovalStatus.APPROVED,
        approverId: employeeIdByKey.manager,
        actedAt: daysAgo(9),
      },
      {
        employeeId: employeeIdByKey.e1,
        leaveTypeId: earned.id,
        startDate: addDays(new Date(), 12),
        endDate: addDays(new Date(), 16),
        days: 5,
        reason: "Pre-planned vacation.",
        status: ApprovalStatus.PENDING,
        approverId: employeeIdByKey.manager,
      },
      {
        employeeId: employeeIdByKey.e8,
        leaveTypeId: casual.id,
        startDate: daysAgo(20),
        endDate: daysAgo(19),
        days: 2,
        reason: "Personal work.",
        status: ApprovalStatus.REJECTED,
        approverId: employeeIdByKey.e7,
        actedAt: daysAgo(19),
        comments: "Quarter-end sales push — please re-schedule for next month.",
      },
    ],
  });

  // ── Payroll: last two months, processed ──────────────────────────
  const now = new Date();
  for (const offset of [2, 1]) {
    const period = new Date(now.getFullYear(), now.getMonth() - offset, 1);
    const run = await prisma.payrollRun.create({
      data: {
        companyId: company.id,
        month: period.getMonth() + 1,
        year: period.getFullYear(),
        status: PayrollStatus.LOCKED,
        processedAt: addDays(period, 28),
        approvedAt: addDays(period, 29),
        lockedAt: addDays(period, 30),
      },
    });

    for (const se of seedEmployees) {
      const structure = {
        basic: se.basic,
        hra: Math.round(se.basic * 0.4),
        conveyance: 1600,
        medicalAllowance: 1250,
        specialAllowance: Math.round(se.basic * 0.15),
        pfEmployee: Math.round(se.basic * 0.12),
        esi: se.basic < 21000 ? Math.round(se.basic * 0.0075) : 0,
        professionalTax: 200,
        tds: 0,
      };
      const gross = structure.basic + structure.hra + structure.conveyance + structure.medicalAllowance + structure.specialAllowance;
      const lopDays = offset === 1 ? randomBetween(0, 1) : 0;
      const perDay = gross / 30;
      const lopDeduction = Math.round(perDay * lopDays);
      const deductions = structure.pfEmployee + structure.esi + structure.professionalTax + structure.tds + lopDeduction;
      const net = Math.round(gross - deductions);

      await prisma.payslip.create({
        data: {
          payrollRunId: run.id,
          employeeId: employeeIdByKey[se.key],
          grossPay: gross,
          totalDeductions: deductions,
          netPay: net,
          workingDays: 30,
          paidDays: 30 - lopDays,
          lopDays,
          // Flat shape — must match what the live runPayroll action writes
          // (lib/actions/payroll.ts), since the payslip print page reads
          // these fields directly rather than a nested "earnings" object.
          breakdown: { ...structure, lopDays, lopDeduction },
        },
      });
    }
  }

  // ── Recruitment ─────────────────────────────────────────────────
  const jobEng = await prisma.jobPosting.create({
    data: {
      companyId: company.id,
      title: "Senior Backend Engineer",
      department: "Engineering",
      location: "Pune HQ / Remote",
      openings: 2,
      description: "Own core services powering attendance and payroll at scale. 5+ years with Node.js/Postgres.",
      status: JobStatus.OPEN,
      postedById: employeeIdByKey.hr,
    },
  });
  const jobSales = await prisma.jobPosting.create({
    data: {
      companyId: company.id,
      title: "Enterprise Account Executive",
      department: "Sales",
      location: "Bengaluru",
      openings: 1,
      description: "Carry a quota selling Verdant HR into mid-market companies across South India.",
      status: JobStatus.OPEN,
      postedById: employeeIdByKey.hr,
    },
  });
  await prisma.jobPosting.create({
    data: {
      companyId: company.id,
      title: "People Operations Intern",
      department: "People Operations",
      location: "Pune HQ",
      openings: 1,
      description: "Support onboarding, documentation and helpdesk triage for a summer term.",
      status: JobStatus.CLOSED,
      postedById: employeeIdByKey.hr,
    },
  });

  const candidateDefs = [
    { job: jobEng, name: "Tanvi Rangan", stage: CandidateStage.INTERVIEW },
    { job: jobEng, name: "Suresh Pillai", stage: CandidateStage.SCREENING },
    { job: jobEng, name: "Wei Zhang", stage: CandidateStage.OFFERED },
    { job: jobEng, name: "Alina George", stage: CandidateStage.APPLIED },
    { job: jobSales, name: "Rakesh Bhatia", stage: CandidateStage.INTERVIEW },
    { job: jobSales, name: "Priyanka Suri", stage: CandidateStage.REJECTED },
  ];
  for (const c of candidateDefs) {
    const candidate = await prisma.candidate.create({
      data: {
        jobPostingId: c.job.id,
        name: c.name,
        email: `${c.name.split(" ")[0].toLowerCase()}@example.com`,
        phone: `9${randomBetween(100000000, 999999999)}`,
        stage: c.stage,
        notes: "Sourced via referral network.",
      },
    });
    if (["INTERVIEW", "OFFERED"].includes(c.stage)) {
      await prisma.interview.create({
        data: {
          candidateId: candidate.id,
          round: "Round 1 — Technical",
          interviewerId: employeeIdByKey.manager,
          scheduledAt: daysAgo(randomBetween(2, 8)),
          feedback: "Strong problem solving, good communication.",
          rating: randomBetween(3, 5),
        },
      });
    }
  }

  // ── Performance ─────────────────────────────────────────────────
  const cycle = await prisma.reviewCycle.create({
    data: {
      companyId: company.id,
      name: `H2 ${year} Review Cycle`,
      startDate: new Date(year, 6, 1),
      endDate: new Date(year, 11, 31),
      status: CycleStatus.ACTIVE,
    },
  });
  const goalTitles = [
    "Ship the new attendance regularization flow",
    "Reduce payroll processing time by 30%",
    "Mentor two junior engineers",
    "Close Q3 enterprise pipeline",
    "Improve onboarding NPS to 8.5+",
  ];
  for (const emp of allEmployees.slice(0, 8)) {
    await prisma.goal.create({
      data: {
        employeeId: emp.id,
        cycleId: cycle.id,
        title: goalTitles[randomBetween(0, goalTitles.length - 1)],
        weight: randomBetween(20, 40),
        rating: randomBetween(3, 5),
      },
    });
    await prisma.performanceReview.create({
      data: {
        employeeId: emp.id,
        cycleId: cycle.id,
        reviewerId: emp.managerId ?? employeeIdByKey.admin,
        rating: randomBetween(3, 5),
        comments: "Consistently reliable delivery with room to grow on stakeholder communication.",
        status: ApprovalStatus.PENDING,
      },
    });
  }

  // ── Expenses ────────────────────────────────────────────────────
  await prisma.expenseClaim.createMany({
    data: [
      { employeeId: employeeIdByKey.employee, category: "Travel", amount: 2450, description: "Cab fares for client visit.", status: ApprovalStatus.PENDING, approverId: employeeIdByKey.manager },
      { employeeId: employeeIdByKey.e2, category: "Software", amount: 1200, description: "JetBrains license renewal.", status: ApprovalStatus.APPROVED, approverId: employeeIdByKey.manager, actedAt: daysAgo(4) },
      { employeeId: employeeIdByKey.e7, category: "Client Entertainment", amount: 5600, description: "Dinner with prospective enterprise client.", status: ApprovalStatus.PENDING, approverId: employeeIdByKey.admin },
      { employeeId: employeeIdByKey.e5, category: "Training", amount: 8000, description: "UX certification course.", status: ApprovalStatus.REJECTED, approverId: employeeIdByKey.admin, actedAt: daysAgo(15), comments: "Please resubmit under the annual learning budget instead." },
      { employeeId: employeeIdByKey.e9, category: "Office Supplies", amount: 750, description: "Stationery for finance team.", status: ApprovalStatus.APPROVED, approverId: employeeIdByKey.admin, actedAt: daysAgo(2) },
    ],
  });

  // ── Documents ───────────────────────────────────────────────────
  await prisma.document.createMany({
    data: [
      { companyId: company.id, employeeId: employeeIdByKey.employee, name: "Offer Letter — Rohan Verma.pdf", type: DocumentType.OFFER_LETTER, fileUrl: "#" },
      { companyId: company.id, employeeId: employeeIdByKey.employee, name: "PAN Card.pdf", type: DocumentType.ID_PROOF, fileUrl: "#" },
      { companyId: company.id, employeeId: null, name: "Leave & Attendance Policy 2026.pdf", type: DocumentType.POLICY, fileUrl: "#" },
      { companyId: company.id, employeeId: null, name: "Code of Conduct.pdf", type: DocumentType.POLICY, fileUrl: "#" },
      { companyId: company.id, employeeId: employeeIdByKey.e5, name: "Employment Contract — Ishita Bose.pdf", type: DocumentType.CONTRACT, fileUrl: "#", expiryDate: addDays(new Date(), 200) },
    ],
  });

  // ── Assets ──────────────────────────────────────────────────────
  const assetDefs = [
    { name: "MacBook Pro 14\"", category: "Laptop", serial: "MW-LAP-1042", employeeKey: "employee" },
    { name: "Dell XPS 15", category: "Laptop", serial: "MW-LAP-1043", employeeKey: "e2" },
    { name: "iPhone 15", category: "Phone", serial: "MW-PHN-2091", employeeKey: "e7" },
    { name: "LG UltraFine Monitor", category: "Monitor", serial: "MW-MON-3110", employeeKey: null },
    { name: "MacBook Air M2", category: "Laptop", serial: "MW-LAP-1044", employeeKey: null },
  ];
  for (const a of assetDefs) {
    const asset = await prisma.asset.create({
      data: {
        companyId: company.id,
        name: a.name,
        category: a.category,
        serialNumber: a.serial,
        status: a.employeeKey ? AssetStatus.ASSIGNED : AssetStatus.AVAILABLE,
        purchaseDate: daysAgo(randomBetween(60, 500)),
      },
    });
    if (a.employeeKey) {
      await prisma.assetAssignment.create({
        data: { assetId: asset.id, employeeId: employeeIdByKey[a.employeeKey], assignedDate: daysAgo(randomBetween(30, 300)) },
      });
    }
  }

  // ── Helpdesk ────────────────────────────────────────────────────
  const ticket1 = await prisma.ticket.create({
    data: {
      companyId: company.id,
      raisedById: employeeIdByKey.employee,
      category: "IT",
      subject: "VPN keeps disconnecting",
      description: "VPN drops every ~20 minutes while working from home.",
      status: TicketStatus.IN_PROGRESS,
      priority: TicketPriority.HIGH,
      assignedToId: employeeIdByKey.e4,
    },
  });
  await prisma.ticketComment.create({
    data: { ticketId: ticket1.id, authorId: employeeIdByKey.e4, message: "Escalated to network vendor, checking firmware on your assigned router profile." },
  });
  await prisma.ticket.create({
    data: {
      companyId: company.id,
      raisedById: employeeIdByKey.e9,
      category: "Payroll",
      subject: "Incorrect HRA in last payslip",
      description: "My HRA looks lower than expected for last month's cycle.",
      status: TicketStatus.OPEN,
      priority: TicketPriority.MEDIUM,
      assignedToId: employeeIdByKey.hr,
    },
  });
  await prisma.ticket.create({
    data: {
      companyId: company.id,
      raisedById: employeeIdByKey.e6,
      category: "Facilities",
      subject: "AC not working on 3rd floor",
      description: "Design bay on the 3rd floor has had no AC since Monday.",
      status: TicketStatus.RESOLVED,
      priority: TicketPriority.LOW,
      assignedToId: employeeIdByKey.hr,
      resolvedAt: daysAgo(2),
    },
  });

  // ── Notifications for demo logins ──────────────────────────────
  await prisma.notification.createMany({
    data: [
      { userId: userIdByKey.manager, title: "Leave request awaiting you", message: "Rohan Verma applied for 2 days of casual leave.", link: "/dashboard/leave/approvals" },
      { userId: userIdByKey.manager, title: "Attendance regularization pending", message: "2 regularization requests need your review.", link: "/dashboard/attendance/regularizations" },
      { userId: userIdByKey.hr, title: "New expense claim", message: "Divya Menon submitted a client entertainment claim.", link: "/dashboard/expenses" },
      { userId: userIdByKey.employee, title: "Payslip published", message: "Your payslip for last month is ready to view.", link: "/dashboard/payroll" },
      { userId: userIdByKey.admin, title: "New helpdesk ticket", message: "A payroll query ticket was raised by Neha Joshi.", link: "/dashboard/helpdesk" },
    ],
  });

  console.log("Seed complete.");
  console.log("──────────────────────────────────────────────");
  console.log("Demo logins (password for all: " + DEMO_PASSWORD + ")");
  console.log("  Super Admin    superadmin@verdanthr.io");
  console.log("  Company Admin  admin@meridianworks.com");
  console.log("  HR             hr@meridianworks.com");
  console.log("  Manager        manager@meridianworks.com");
  console.log("  Employee       employee@meridianworks.com");
  console.log("  Recruiter      recruiter@meridianworks.com");
  console.log("  Payroll Admin  payroll@meridianworks.com");
  console.log("  Finance        finance@meridianworks.com");
  console.log("  IT / Admin     it@meridianworks.com");
  console.log("  Auditor        auditor@meridianworks.com");
  console.log("  Management     management@meridianworks.com");
  console.log("──────────────────────────────────────────────");
}

main()
  .catch((e) => {
    console.error(e);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
