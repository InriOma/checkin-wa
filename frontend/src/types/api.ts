export type TenantRole = 'company_owner' | 'hr_admin' | 'branch_manager' | 'employee';

export interface TenantMembership {
  tenant_id: number;
  tenant_public_id: string | null;
  tenant_code: string | null;
  tenant_name: string | null;
  role: TenantRole;
  status: string;
  branch_scope_ids: number[] | null;
}

export interface User {
  id: number;
  name: string;
  email: string;
  is_platform_admin: boolean;
  memberships?: TenantMembership[];
}

export interface Tenant {
  id: number;
  public_id: string;
  name: string;
  code: string;
  timezone: string;
  status: 'active' | 'suspended' | 'trial';
  plan_limits: Record<string, number> | null;
  created_at?: string;
}

export interface Branch {
  id: number;
  tenant_id: number;
  code: string;
  name: string;
  address: string | null;
  latitude: number;
  longitude: number;
  geofence_radius_meters: number;
  timezone: string;
  status: 'active' | 'inactive';
}

export interface EmployeeBranchAssignment {
  id: number;
  branch_id: number;
  branch_code: string | null;
  branch_name: string | null;
  effective_from: string | null;
  effective_until: string | null;
}

export interface Employee {
  id: number;
  tenant_id: number;
  user_id: number | null;
  employee_code: string;
  first_name: string;
  last_name: string;
  full_name: string;
  email: string | null;
  default_branch_id: number | null;
  default_branch?: Branch | null;
  branch_assignments?: EmployeeBranchAssignment[];
  status: 'active' | 'inactive' | 'suspended';
  hired_at: string | null;
}

export interface EmployeeWhatsAppLink {
  id: number;
  tenant_id: number;
  employee_id: number;
  whatsapp_contact_id: number;
  phone_masked: string | null;
  is_primary: boolean;
  verified_at: string | null;
  linked_via_channel_id: number | null;
}

export interface Schedule {
  id: number;
  tenant_id: number;
  name: string;
  timezone: string;
  work_days: number[];
  start_time: string;
  end_time: string;
  is_overnight: boolean;
  late_tolerance_minutes: number;
  absence_threshold_minutes: number;
  break_duration_minutes: number;
  max_breaks_per_shift: number;
  status: 'active' | 'inactive';
}

export interface AttendanceEvent {
  id: number;
  event_type: 'clock_in' | 'break_start' | 'break_end' | 'clock_out' | 'correction' | 'rejected_geofence';
  source: string;
  branch_id: number | null;
  wamid: string | null;
  received_at: string;
  event_at: string;
  latitude: number | null;
  longitude: number | null;
  distance_meters: number | null;
  geofence_radius_meters: number | null;
  geofence_passed: boolean | null;
  notes: string | null;
}

export interface AttendanceBreak {
  id: number;
  started_at: string;
  ended_at: string | null;
  duration_minutes: number | null;
}

export interface AttendanceRecord {
  id: number;
  tenant_id: number;
  employee_id: number;
  branch_id: number | null;
  schedule_id: number | null;
  work_date: string;
  status: 'working' | 'on_break' | 'closed';
  clock_in_at: string;
  clock_out_at: string | null;
  total_worked_minutes: number | null;
  total_break_minutes: number;
  late_minutes: number;
  early_departure_minutes: number;
  employee?: Employee;
  branch?: Branch | null;
  events?: AttendanceEvent[];
  breaks?: AttendanceBreak[];
}

export interface Incident {
  id: number;
  tenant_id: number;
  employee_id: number;
  attendance_record_id: number | null;
  branch_id: number | null;
  incident_date: string;
  type: 'late' | 'absence' | 'early_departure' | 'incomplete_shift' | 'excess_break' | 'outside_geofence';
  status: 'pending' | 'approved' | 'rejected';
  minutes_affected: number | null;
  justification_note: string | null;
  metadata: Record<string, unknown> | null;
  reviewed_at: string | null;
  employee?: Employee;
  branch?: Branch | null;
}

export interface Justification {
  id: number;
  tenant_id: number;
  employee_id: number;
  start_date: string;
  end_date: string;
  reason_type: 'medical' | 'vacation' | 'personal' | 'permission' | 'other';
  notes: string | null;
  status: 'pending' | 'approved' | 'rejected';
  reviewed_at: string | null;
  employee?: Employee;
}

export interface WhatsAppChannel {
  id: number;
  tenant_id: number | null;
  channel_type: 'central' | 'dedicated';
  phone_number_id: string;
  business_account_id: string;
  display_phone_number: string;
  token_configured: boolean;
  status: 'active' | 'inactive' | 'pending_verification';
}

export interface AttendanceSummary {
  period: {
    from_date: string;
    to_date: string;
  };
  attendance: {
    total_records: number;
    open_shifts: number;
    closed_shifts: number;
    on_time_count: number;
    late_count: number;
    total_late_minutes: number;
    total_worked_minutes: number;
  };
  incidents: {
    pending_count: number;
    by_type: Record<string, number>;
  };
}

export interface PaginatedResponse<T> {
  data: T[];
  meta?: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}
