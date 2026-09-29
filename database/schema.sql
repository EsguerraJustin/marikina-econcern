CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  mobile VARCHAR(30) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS departments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_departments_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS concern_types (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  department_id INT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_concern_types_dept_name (department_id, name),
  KEY idx_concern_types_dept (department_id),
  CONSTRAINT fk_concern_types_department
    FOREIGN KEY (department_id) REFERENCES departments (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL,
  email VARCHAR(190) NOT NULL,
  mobile VARCHAR(20) NULL COMMENT 'PH mobile for SMS OTP (E.164 +639xxxxx or local 09xxxxx)',
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin','department_admin') NOT NULL,
  department_id INT UNSIGNED NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Failed login brute-force counter',
  locked_until TIMESTAMP NULL DEFAULT NULL COMMENT 'Temporary lock expiry after too many failed attempts',
  last_login_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Last successful login timestamp',
  ui_theme VARCHAR(20) NOT NULL DEFAULT 'light',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_admins_email (email),
  KEY idx_admins_mobile (mobile),
  KEY idx_admins_role (role),
  KEY idx_admins_department (department_id),
  CONSTRAINT fk_admins_department
    FOREIGN KEY (department_id) REFERENCES departments (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS concerns (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_number VARCHAR(20) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  concern_type_id INT UNSIGNED NOT NULL,
  assigned_admin_id INT UNSIGNED NULL,
  street VARCHAR(190) NOT NULL,
  barangay VARCHAR(120) NOT NULL,
  landmark VARCHAR(190) NOT NULL,
  description TEXT NOT NULL,
  status ENUM('New','Ongoing','Acknowledge','Completed','Cancelled') NOT NULL DEFAULT 'New',
  photos_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_concerns_report_number (report_number),
  KEY idx_concerns_user (user_id),
  KEY idx_concerns_status (status),
  KEY idx_concerns_type (concern_type_id),
  KEY idx_concerns_assigned_admin (assigned_admin_id),
  CONSTRAINT fk_concerns_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_concerns_type
    FOREIGN KEY (concern_type_id) REFERENCES concern_types (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
  ,
  CONSTRAINT fk_concerns_assigned_admin
    FOREIGN KEY (assigned_admin_id) REFERENCES admins (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS concern_timeline (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  concern_id INT UNSIGNED NOT NULL,
  status ENUM('New','Ongoing','Acknowledge','Completed','Cancelled') NOT NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_timeline_concern (concern_id),
  CONSTRAINT fk_timeline_concern
    FOREIGN KEY (concern_id) REFERENCES concerns (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS concern_messages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  concern_id INT UNSIGNED NOT NULL,
  sender ENUM('user','department') NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_messages_concern (concern_id),
  CONSTRAINT fk_messages_concern
    FOREIGN KEY (concern_id) REFERENCES concerns (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS concern_notes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  concern_id INT UNSIGNED NOT NULL,
  admin_id INT UNSIGNED NULL,
  note TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notes_concern (concern_id),
  KEY idx_notes_admin (admin_id),
  CONSTRAINT fk_notes_concern
    FOREIGN KEY (concern_id) REFERENCES concerns (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_notes_admin
    FOREIGN KEY (admin_id) REFERENCES admins (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO departments (name) VALUES
('Community Relations Office'),
('Engineering Office'),
('Human Resource Management Office'),
('Marikina City Tourism and Cultural Office'),
('Marikina Sports Center'),
('Office for Senior Citizen''s Affairs'),
('Office of Public Safety and Security'),
('Office of the Mayor'),
('Parks Development Office'),
('River Parks Authority'),
('School Repair and Maintenance Office'),
('Animal Rescue and Shelter'),
('Business Permit and License Office'),
('City Environmental Management Office'),
('City Health Office'),
('City Public Market Office'),
('City Social Welfare Development'),
('City Treasury Office')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO concern_types (department_id, name)
SELECT d.id, t.name
FROM departments d
JOIN (
  SELECT 'Community Relations Office' AS dept, 'Community / HOA Concerns' AS name

  UNION ALL SELECT 'Engineering Office', 'Permits'
  UNION ALL SELECT 'Engineering Office', 'Building and Construction'
  UNION ALL SELECT 'Engineering Office', 'Electrical Concerns'
  UNION ALL SELECT 'Engineering Office', 'Cable Bundling'
  UNION ALL SELECT 'Engineering Office', 'Traffic Light Repair'
  UNION ALL SELECT 'Engineering Office', 'Street Light Repair'
  UNION ALL SELECT 'Engineering Office', 'MWC Restoration'
  UNION ALL SELECT 'Engineering Office', 'Street Repair & Sidewalk Maintenance'
  UNION ALL SELECT 'Engineering Office', 'Declogging of Public Canals and Drains'

  UNION ALL SELECT 'Human Resource Management Office', 'Employee Concerns / Feedback'

  UNION ALL SELECT 'Marikina City Tourism and Cultural Office', 'Tours and Promotions'

  UNION ALL SELECT 'Marikina Sports Center', 'Schedule'
  UNION ALL SELECT 'Marikina Sports Center', 'Booking'
  UNION ALL SELECT 'Marikina Sports Center', 'Events'

  UNION ALL SELECT 'Office for Senior Citizen''s Affairs', 'Issuance of SC ID'
  UNION ALL SELECT 'Office for Senior Citizen''s Affairs', 'Birthday Subsidy'

  UNION ALL SELECT 'Office of Public Safety and Security', 'Illegal Parking/Road Obstructions'
  UNION ALL SELECT 'Office of Public Safety and Security', 'Illegal Vendors/Ordinance Violations'
  UNION ALL SELECT 'Office of Public Safety and Security', 'Public Order and Safety'
  UNION ALL SELECT 'Office of Public Safety and Security', 'Public Transportation'
  UNION ALL SELECT 'Office of Public Safety and Security', 'Complaint/Concerns'
  UNION ALL SELECT 'Office of Public Safety and Security', 'Violation Inquiries'

  UNION ALL SELECT 'Office of the Mayor', 'Other(s): Pls specify'

  UNION ALL SELECT 'Parks Development Office', 'Fabrication/Renovation/Repair/Repainting of Park Furniture'
  UNION ALL SELECT 'Parks Development Office', 'Parks and Playgrounds Development, Landscaping'
  UNION ALL SELECT 'Parks Development Office', 'Park Cleaning/Clearing, Grass Cutting, Soil Leveling'
  UNION ALL SELECT 'Parks Development Office', 'Tree Planting/Tree Maintenance'
  UNION ALL SELECT 'Parks Development Office', 'Tree Trimming'

  UNION ALL SELECT 'River Parks Authority', 'Riverparks Maintenance'
  UNION ALL SELECT 'River Parks Authority', 'Photoshoot/Educational Activities'
  UNION ALL SELECT 'River Parks Authority', 'Riverpark Restaurants'
  UNION ALL SELECT 'River Parks Authority', 'Security & Safety of Park Goers'

  UNION ALL SELECT 'School Repair and Maintenance Office', 'School Repairs and Requests'

  UNION ALL SELECT 'Animal Rescue and Shelter', 'Pet Registration & Vaccination'
  UNION ALL SELECT 'Animal Rescue and Shelter', 'Stray Animals'

  UNION ALL SELECT 'Business Permit and License Office', 'Business Inquiries'
  UNION ALL SELECT 'Business Permit and License Office', 'Permits'
  UNION ALL SELECT 'Business Permit and License Office', 'Inspection'

  UNION ALL SELECT 'City Environmental Management Office', 'Garbage Collection'
  UNION ALL SELECT 'City Environmental Management Office', 'Hakot Kuyagot'
  UNION ALL SELECT 'City Environmental Management Office', 'Tanker Sidewalk Cleaning and Scrubbing'
  UNION ALL SELECT 'City Environmental Management Office', 'Vacant Lot Grass Cutting and Soil Leveling'
  UNION ALL SELECT 'City Environmental Management Office', 'Eco Bricks'

  UNION ALL SELECT 'City Health Office', 'Permits and Certificates'
  UNION ALL SELECT 'City Health Office', 'CHO Schedule and Services'
  UNION ALL SELECT 'City Health Office', 'Health Center Schedule and Services'
  UNION ALL SELECT 'City Health Office', 'Medical Arts Schedule and Services'
  UNION ALL SELECT 'City Health Office', 'Animal Bite Treatment Center'

  UNION ALL SELECT 'City Public Market Office', 'Consumer Welfare Assistance/Complaints'

  UNION ALL SELECT 'City Social Welfare Development', 'PWD Registration'
  UNION ALL SELECT 'City Social Welfare Development', 'Solo Parents Registration'

  UNION ALL SELECT 'City Treasury Office', 'Real Property Tax'
  UNION ALL SELECT 'City Treasury Office', 'Business Tax'
  UNION ALL SELECT 'City Treasury Office', 'Other Payments'
) t ON t.dept = d.name
ON DUPLICATE KEY UPDATE name = VALUES(name);
