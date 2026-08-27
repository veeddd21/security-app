CREATE TABLE IF NOT EXISTS organizations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  code VARCHAR(80) NOT NULL UNIQUE,
  contact_email VARCHAR(190) DEFAULT NULL,
  phone VARCHAR(40) DEFAULT NULL,
  logo_url VARCHAR(255) DEFAULT NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  plan ENUM('starter','professional','enterprise') NOT NULL DEFAULT 'starter',
  guard_limit INT NOT NULL DEFAULT 50,
  subscription_status ENUM('trial','active','past_due','cancelled') NOT NULL DEFAULT 'active',
  duty_labels JSON DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS duty_sites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT DEFAULT NULL,
  customer_id INT DEFAULT NULL,
  name VARCHAR(190) NOT NULL,
  area VARCHAR(190) DEFAULT NULL,
  address VARCHAR(255) DEFAULT NULL,
  latitude DECIMAL(10,6) DEFAULT NULL,
  longitude DECIMAL(10,6) DEFAULT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_duty_sites_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(190) NOT NULL,
  description TEXT DEFAULT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_customers_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS customer_locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  organization_id INT NOT NULL,
  name VARCHAR(190) NOT NULL,
  area VARCHAR(190) DEFAULT NULL,
  address VARCHAR(255) DEFAULT NULL,
  latitude DECIMAL(10,6) DEFAULT NULL,
  longitude DECIMAL(10,6) DEFAULT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_customer_locations_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_locations_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS customer_guard_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  customer_location_id INT NOT NULL,
  guard_id INT NOT NULL,
  organization_id INT NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_customer_assignments_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_assignments_location FOREIGN KEY (customer_location_id) REFERENCES customer_locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_assignments_guard FOREIGN KEY (guard_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_assignments_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT DEFAULT NULL,
  duty_site_id INT DEFAULT NULL,
  role ENUM('super_admin','admin','guard') NOT NULL,
  full_name VARCHAR(190) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  phone VARCHAR(40) DEFAULT NULL,
  employee_code VARCHAR(80) DEFAULT NULL,
  shift_label VARCHAR(120) DEFAULT NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  avatar_url VARCHAR(255) DEFAULT NULL,
  identity_photo_path VARCHAR(255) DEFAULT NULL,
  identity_selfie_path VARCHAR(255) DEFAULT NULL,
  identity_enrolled_at DATETIME DEFAULT NULL,
  session_version INT NOT NULL DEFAULT 1,
  last_login_at DATETIME DEFAULT NULL,
  last_seen_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_users_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
  CONSTRAINT fk_users_duty_site FOREIGN KEY (duty_site_id) REFERENCES duty_sites(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  organization_id INT DEFAULT NULL,
  location_label VARCHAR(190) DEFAULT NULL,
  note TEXT DEFAULT NULL,
  check_in_at DATETIME NOT NULL,
  check_out_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_attendance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  organization_id INT DEFAULT NULL,
  latitude DECIMAL(10,6) NOT NULL,
  longitude DECIMAL(10,6) NOT NULL,
  accuracy_meters INT DEFAULT NULL,
  address VARCHAR(255) DEFAULT NULL,
  duty_label VARCHAR(120) DEFAULT NULL,
  tracked_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_locations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS selfies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  organization_id INT DEFAULT NULL,
  image_path VARCHAR(255) NOT NULL,
  captured_at DATETIME NOT NULL,
  identity_status VARCHAR(50) NOT NULL DEFAULT 'captured',
  reference_image_path VARCHAR(255) DEFAULT NULL,
  verification_score DECIMAL(5,4) DEFAULT NULL,
  verification_passed TINYINT(1) NOT NULL DEFAULT 0,
  verification_method VARCHAR(50) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_selfies_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS activities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  organization_id INT DEFAULT NULL,
  type VARCHAR(80) NOT NULL,
  title VARCHAR(190) NOT NULL,
  details TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL
);

INSERT INTO organizations (name, code, contact_email, phone, status, plan, guard_limit, subscription_status, created_at, updated_at)
SELECT 'Infipre Security', 'INFIPRE', 'ops@infipre.local', '+91 90000 00000', 'active', 'enterprise', 250, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM organizations WHERE code = 'INFIPRE');

INSERT INTO users (organization_id, duty_site_id, role, full_name, email, password_hash, phone, employee_code, shift_label, status, created_at, updated_at, identity_enrolled_at, session_version, last_seen_at)
SELECT
  o.id,
  NULL,
  'super_admin',
  'Richard Infipre',
  'richard.infipre@gmail.com',
  '{PASSWORD_SUPER}',
  '+91 90000 00001',
  'SA-001',
  'HQ',
  'active',
  NOW(),
  NOW(),
  NOW(),
  1,
  NOW()
FROM organizations o
WHERE o.code = 'INFIPRE'
  AND NOT EXISTS (SELECT 1 FROM users WHERE email = 'richard.infipre@gmail.com');

INSERT INTO users (organization_id, duty_site_id, role, full_name, email, password_hash, phone, employee_code, shift_label, status, created_at, updated_at, identity_enrolled_at, session_version, last_seen_at)
SELECT
  o.id,
  NULL,
  'admin',
  'Infipre Admin',
  'admin@infipre.local',
  '{PASSWORD_ADMIN}',
  '+91 90000 00002',
  'AD-001',
  'Control Room',
  'active',
  NOW(),
  NOW(),
  NOW(),
  1,
  NOW()
FROM organizations o
WHERE o.code = 'INFIPRE'
  AND NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin@infipre.local');

INSERT INTO users (organization_id, duty_site_id, role, full_name, email, password_hash, phone, employee_code, shift_label, status, created_at, updated_at, identity_enrolled_at, session_version, last_seen_at)
SELECT
  o.id,
  NULL,
  'guard',
  'Infipre Guard',
  'guard@infipre.local',
  '{PASSWORD_GUARD}',
  '+91 90000 00003',
  'GR-001',
  'Field checkpoint',
  'active',
  NOW(),
  NOW(),
  NOW(),
  1,
  NOW()
FROM organizations o
WHERE o.code = 'INFIPRE'
  AND NOT EXISTS (SELECT 1 FROM users WHERE email = 'guard@infipre.local');
