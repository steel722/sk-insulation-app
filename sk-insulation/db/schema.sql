-- SK Insulation — esquema MySQL/MariaDB
CREATE DATABASE IF NOT EXISTS sk_insulation CHARACTER SET utf8mb4;
USE sk_insulation;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL CHECK (role IN ('manager','installer')),
    phone VARCHAR(40),
    active TINYINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS crews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    lead_user_id INT NULL,
    active TINYINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS crew_members (
    crew_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (crew_id, user_id),
    FOREIGN KEY (crew_id) REFERENCES crews(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS pay_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    work_type VARCHAR(40) NOT NULL UNIQUE,
    label VARCHAR(120) NOT NULL,
    rate DECIMAL(10,2) NOT NULL,
    unit VARCHAR(5) NOT NULL DEFAULT 'SF' CHECK (unit IN ('SF','LF')),
    active TINYINT NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS materials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    brand VARCHAR(40) NOT NULL CHECK (brand IN ('Owens Corning','Knauf','Mixed')),
    product_code VARCHAR(40),
    name VARCHAR(160) NOT NULL,
    r_value VARCHAR(20),
    thickness_in DECIMAL(6,2),
    width_in DECIMAL(6,2),
    length_ft DECIMAL(6,2),
    sf_per_bag DECIMAL(8,2),
    active TINYINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_number VARCHAR(40) NOT NULL UNIQUE,
    customer VARCHAR(160) NOT NULL,
    builder VARCHAR(160),
    lot_number VARCHAR(40),
    address VARCHAR(255),
    install_date DATE,
    crew_id INT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'scheduled'
        CHECK (status IN ('scheduled','in_progress','completed','cancelled')),
    notes TEXT,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    FOREIGN KEY (crew_id) REFERENCES crews(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS job_materials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    material_id INT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    note VARCHAR(255),
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (material_id) REFERENCES materials(id)
);

CREATE TABLE IF NOT EXISTS production (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    installer_id INT NOT NULL,
    work_date DATE NOT NULL,
    work_type VARCHAR(40) NOT NULL,
    square_feet DECIMAL(10,2) NOT NULL DEFAULT 0,
    linear_feet DECIMAL(10,2) NOT NULL DEFAULT 0,
    note VARCHAR(255),
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (installer_id) REFERENCES users(id),
    FOREIGN KEY (work_type) REFERENCES pay_rates(work_type),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_production_week (work_date, installer_id),
    INDEX idx_production_job (job_id)
);

CREATE TABLE IF NOT EXISTS pay_splits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    installer_id INT NOT NULL,
    percent DECIMAL(5,2) NOT NULL CHECK (percent >= 0 AND percent <= 100),
    UNIQUE KEY uq_split (job_id, installer_id),
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (installer_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS payroll_weeks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    week_start DATE NOT NULL,
    week_end DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed')),
    closed_at DATETIME NULL,
    UNIQUE KEY uq_week (week_start)
);

CREATE TABLE IF NOT EXISTS adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    week_start DATE NOT NULL,
    installer_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (installer_id) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Tarifas de pago (de tu especificación)
INSERT IGNORE INTO pay_rates (work_type, label, rate, unit) VALUES
    ('wall',              'Wall Insulation (R-13 - R-21)',  0.12, 'SF'),
    ('ceiling_r30',       'Ceiling Insulation (R-30+)',     0.14, 'SF'),
    ('blow_in',           'Blow-In (fill walls & cut cap)', 0.90, 'SF'),
    ('basement_blanket',  'Basement Blankets',              0.14, 'SF'),
    ('spray_foam',        'Spray Foam',                     0.10, 'SF'),
    ('batts_air_sealing', 'Batts / Air Sealing',            0.10, 'SF'),
    ('gutters',           'Gutters',                        0.20, 'LF'),
    ('demolition',        'Demolition',                     0.69, 'SF'),
    ('general',           'General Work',                   0.10, 'SF');

-- Materiales comunes
INSERT IGNORE INTO materials (brand, product_code, name, r_value, thickness_in, width_in, length_ft, sf_per_bag) VALUES
    ('Owens Corning', 'OC-R13-15', 'EcoTouch R-13 Kraft Batts 15" x 93"', 'R-13', 3.50, 15, 7.75, 106.60),
    ('Owens Corning', 'OC-R19-15', 'EcoTouch R-19 Kraft Batts 15" x 93"', 'R-19', 6.25, 15, 7.75, 106.60),
    ('Owens Corning', 'OC-R30-16', 'EcoTouch R-30 Batts 16" x 48"',       'R-30', 10.25, 16, 4.00, 42.70),
    ('Owens Corning', 'OC-BLOW',   'AttiCat Expanding Blown-In (bag)',    'R-38', NULL, NULL, NULL, 62.50),
    ('Knauf', 'KN-R13-15', 'EcoBatt R-13 Kraft 15" x 93"',  'R-13', 3.50, 15, 7.75, 106.60),
    ('Knauf', 'KN-R19-15', 'EcoBatt R-19 Kraft 15" x 93"',  'R-19', 6.25, 15, 7.75, 106.60),
    ('Knauf', 'KN-R30-24', 'EcoBatt R-30 24" x 48"',        'R-30', 10.25, 24, 4.00, 64.00),
    ('Knauf', 'KN-BLOW',   'Jet Stream Blown-In (bag)',     'R-38', NULL, NULL, NULL, 60.00);

-- Administrador inicial: admin@skinsulation.local / changeme  (¡cámbiala luego!)
INSERT IGNORE INTO users (name, email, password_hash, role) VALUES
    ('SK Admin', 'admin@skinsulation.local',
     '$2y$10$/126kN9TdipVqUElkVjhnuvlg1Z6sKNufozcymv0yCgMJhLR83Kya', 'manager');
