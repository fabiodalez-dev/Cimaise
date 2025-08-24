-- Test users with different roles for comprehensive testing
INSERT INTO users (email, first_name, last_name, password_hash, role, is_active) VALUES
('mario.rossi@example.com', 'Mario', 'Rossi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 1),
('anna.verdi@example.com', 'Anna', 'Verdi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 1),
('luca.bianchi@example.com', 'Luca', 'Bianchi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1),
('giulia.neri@example.com', 'Giulia', 'Neri', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 0);

-- Note: Password is 'password' for all test users