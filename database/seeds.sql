-- ============================================================
-- CLEANUP (Safely clears all tables and resets IDs)
-- ============================================================
TRUNCATE TABLE activity_log, score, schedule, response, otp_request_log, otp, question_config, applicant, users RESTART IDENTITY CASCADE;

-- ============================================================
-- USERS
-- Passwords are bcrypt hashes. Regenerate with generate_hash.php
-- if you need to change them.
-- The admin logs in with admin@expertise.com, but its normalized email
-- is ebsmi.screening@gmail.com.
-- ============================================================
INSERT INTO users (role, name, email, email_normalized, password_hash) VALUES
    ('admin', 'System Admin', 'admin@expertise.com', 'ebsmi.screening@gmail.com', '$2y$10$oOC0r1YyU3QOURWLlWQ26./BSESWAUkAfop0Dv2zSjgDaNFMePYnG'),
    ('hr',    'HR Staff',     'hr@expertise.com',    'hr@expertise.com',          '$2y$10$KSG4v4z3WMhknlDs8LZf3.d066DL98eITbXnpPboAXQ5P6es4fFuy');


-- =============================================================

-- ============================================================

-- ============================================================

-- ============================================================

-- ============================================================
-- APPLICANTS
-- 50 rows with varying statuses/results (Filipino names, phones starting with 09).
-- ============================================================
INSERT INTO applicant (reference_code, secure_token, encoded_by_user_id,
   name, first_name, middle_name, last_name, name_normalized,
   email, email_normalized, phone_number, cv_file_url, cv_hash, verified_at, status, result,
   invited_at, submitted_at, passed_at, deleted_at, created_at) VALUES
('APP001','e5c2a0de459699756bb9871045cf702c',1, 'Maria Clara Santos', 'Maria', 'Clara', 'Santos', 'mariaclarasantos',
   'maria.santos@example.com', 'maria.santos@example.com', '09169797807', NULL, NULL, '2026-08-03 09:00:00', 'in_progress', NULL, '2026-08-03 08:00:00', NULL, NULL, NULL, '2026-08-01 08:00:00'),
('APP002','8bee8ac7c9ff9285075f5d6c923ae3eb',1, 'Juan  Dela Cruz', 'Juan', '', 'Dela Cruz', 'juandelacruz',
   'juan.dela cruz@example.com', 'juan.dela cruz@example.com', '09404466510', NULL, NULL, '2026-08-03 10:10:00', 'pending', NULL, '2026-08-03 09:10:00', NULL, NULL, NULL, '2026-08-02 09:10:00'),
('APP003','e35e01bcd60f568024ebd38d7940f056',1, 'Ana  Garcia', 'Ana', '', 'Garcia', 'anagarcia',
   'ana.garcia@example.com', 'ana.garcia@example.com', '09730728755', NULL, NULL, '2026-08-03 11:20:00', 'pending', NULL, '2026-08-03 10:20:00', NULL, NULL, NULL, '2026-08-03 10:20:00'),
('APP004','9adc34d6e534c7a0ff953af9a67aad28',1, 'Pedro  Reyes', 'Pedro', '', 'Reyes', 'pedroreyes',
   'pedro.reyes@example.com', 'pedro.reyes@example.com', '09150192221', NULL, NULL, '2026-08-03 12:30:00', 'accepted', 'passed', '2026-08-03 11:30:00', '2026-08-08 11:30:00', '2026-08-12 11:30:00', NULL, '2026-08-04 11:30:00'),
('APP005','11dcf585ed13dc1976decf8e1823b3ad',1, 'Luis  Martinez', 'Luis', '', 'Martinez', 'luismartinez',
   'luis.martinez@example.com', 'luis.martinez@example.com', '09400506609', NULL, NULL, '2026-08-04 13:40:00', 'rejected', 'rejected', '2026-08-04 12:40:00', '2026-08-10 12:40:00', '2026-08-15 12:40:00', NULL, '2026-08-05 12:40:00'),
('APP006','f6df68ff4399c0e3ecfa5bbf86a6c88f',1, 'Rocio  Fernandez', 'Rocio', '', 'Fernandez', 'rociofernandez',
   'rocio.fernandez@example.com', 'rocio.fernandez@example.com', '09901519699', NULL, NULL, '2026-08-04 14:50:00', 'rejected', 'rejected', '2026-08-04 13:50:00', '2026-08-11 13:50:00', '2026-08-17 13:50:00', NULL, '2026-08-06 13:50:00'),
('APP007','bf74a0145afe40ee2cf61f3d39a24e14',1, 'Chen  Wei', 'Chen', '', 'Wei', 'chenwei',
   'chen.wei@example.com', 'chen.wei@example.com', '09907270438', NULL, NULL, '2026-08-04 15:00:00', 'in_progress', NULL, '2026-08-04 14:00:00', NULL, NULL, NULL, '2026-08-07 14:00:00'),
('APP008','4c3d996d25d1e24b729d28e332b6557d',1, 'Sophie  Bernard', 'Sophie', '', 'Bernard', 'sophiebernard',
   'sophie.bernard@example.com', 'sophie.bernard@example.com', '09684656769', NULL, NULL, '2026-08-04 16:10:00', 'pending', NULL, '2026-08-04 15:10:00', NULL, NULL, NULL, '2026-08-08 15:10:00'),
('APP009','63e138d07668b84c27601e8d8986b93e',1, 'Jose  Lopez', 'Jose', '', 'Lopez', 'joselopez',
   'jose.lopez@example.com', 'jose.lopez@example.com', '09750891779', NULL, NULL, '2026-08-05 17:20:00', 'rejected', 'rejected', '2026-08-05 16:20:00', '2026-08-15 16:20:00', '2026-08-18 16:20:00', NULL, '2026-08-09 16:20:00'),
('APP010','164c018d6fcd98a8da2e84166c598a60',1, 'Carmen  Gonzalez', 'Carmen', '', 'Gonzalez', 'carmengonzalez',
   'carmen.gonzalez@example.com', 'carmen.gonzalez@example.com', '09186105642', NULL, NULL, '2026-08-05 09:30:00', 'in_progress', NULL, '2026-08-05 08:30:00', NULL, NULL, NULL, '2026-08-10 17:30:00'),
('APP011','6d0525ba1652bac02e65d39c52a30c01',1, 'Elena  Perez', 'Elena', '', 'Perez', 'elenaperez',
   'elena.perez@example.com', 'elena.perez@example.com', '09326491439', NULL, NULL, '2026-08-05 10:40:00', 'pending', NULL, '2026-08-05 09:40:00', NULL, NULL, NULL, '2026-08-11 08:40:00'),
('APP012','ee06bb4214bb42271711dc4ad857b38f',1, 'Gloria  Sanchez', 'Gloria', '', 'Sanchez', 'gloriasanchez',
   'gloria.sanchez@example.com', 'gloria.sanchez@example.com', '09414284224', NULL, NULL, '2026-08-05 11:50:00', 'pending', NULL, '2026-08-05 10:50:00', NULL, NULL, NULL, '2026-08-12 09:50:00'),
('APP013','02b8126a01974061f6514ae8be700f2d',1, 'Antonio  Ramirez', 'Antonio', '', 'Ramirez', 'antonioramirez',
   'antonio.ramirez@example.com', 'antonio.ramirez@example.com', '09785689527', NULL, NULL, '2026-08-06 12:00:00', 'accepted', 'passed', '2026-08-06 11:00:00', '2026-08-20 11:00:00', '2026-08-21 11:00:00', NULL, '2026-08-13 10:00:00'),
('APP014','1d428cff3c51d721082eef6a666abd3f',1, 'Miguel  Torres', 'Miguel', '', 'Torres', 'migueltorres',
   'miguel.torres@example.com', 'miguel.torres@example.com', '09490004343', NULL, NULL, '2026-08-06 13:10:00', 'rejected', 'rejected', '2026-08-06 12:10:00', '2026-08-08 12:10:00', '2026-08-10 12:10:00', NULL, '2026-09-14 11:10:00'),
('APP015','3d5a1b9b32ac7f03abecfb6544d821eb',1, 'Rafael  Flores', 'Rafael', '', 'Flores', 'rafaelflores',
   'rafael.flores@example.com', 'rafael.flores@example.com', '09189071038', NULL, NULL, '2026-08-06 14:20:00', 'in_progress', NULL, '2026-08-06 13:20:00', NULL, NULL, NULL, '2026-09-15 12:20:00'),
('APP016','52505215d63bf9703f05c49c63488efb',1, 'Francisco  Rivera', 'Francisco', '', 'Rivera', 'franciscorivera',
   'francisco.rivera@example.com', 'francisco.rivera@example.com', '09389194811', NULL, NULL, '2026-08-06 15:30:00', 'rejected', 'rejected', '2026-08-06 14:30:00', '2026-08-10 14:30:00', '2026-08-14 14:30:00', NULL, '2026-09-16 13:30:00'),
('APP017','d1a5ff96d378c3aaf2a8a659144a5a6e',1, 'Manuel  Gomez', 'Manuel', '', 'Gomez', 'manuelgomez',
   'manuel.gomez@example.com', 'manuel.gomez@example.com', '09248829772', NULL, NULL, '2026-08-07 16:40:00', 'accepted', 'passed', '2026-08-07 15:40:00', '2026-08-12 15:40:00', '2026-08-17 15:40:00', NULL, '2026-09-17 14:40:00'),
('APP018','53b65ec5e4df3601e2daf077808bd621',1, 'Ricardo  Diaz', 'Ricardo', '', 'Diaz', 'ricardodiaz',
   'ricardo.diaz@example.com', 'ricardo.diaz@example.com', '09672774163', NULL, NULL, '2026-08-07 17:50:00', 'pending', NULL, '2026-08-07 16:50:00', NULL, NULL, NULL, '2026-09-18 15:50:00'),
('APP019','0e2eecac4d3667e8c28a302d0f96165f',1, 'Roberto  Morales', 'Roberto', '', 'Morales', 'robertomorales',
   'roberto.morales@example.com', 'roberto.morales@example.com', '09480642601', NULL, NULL, '2026-08-07 09:00:00', 'accepted', 'passed', '2026-08-07 08:00:00', '2026-08-14 08:00:00', '2026-08-15 08:00:00', NULL, '2026-09-19 16:00:00'),
('APP020','3f06de621ae4118780e8fb5c551612a6',1, 'Ruben  Gutierrez', 'Ruben', '', 'Gutierrez', 'rubengutierrez',
   'ruben.gutierrez@example.com', 'ruben.gutierrez@example.com', '09685424604', NULL, NULL, '2026-08-07 10:10:00', 'in_progress', NULL, '2026-08-07 09:10:00', NULL, NULL, NULL, '2026-09-20 17:10:00'),
('APP021','f64a142f1e997f60b18580eb207842ab',1, 'Alberto  Ramos', 'Alberto', '', 'Ramos', 'albertoramos',
   'alberto.ramos@example.com', 'alberto.ramos@example.com', '09806392196', NULL, NULL, '2026-08-08 11:20:00', 'in_progress', NULL, '2026-08-08 10:20:00', NULL, NULL, NULL, '2026-09-21 08:20:00'),
('APP022','ac78a467f42f43d5cd7bff8261631caa',1, 'Armando  Aquino', 'Armando', '', 'Aquino', 'armandoaquino',
   'armando.aquino@example.com', 'armando.aquino@example.com', '09134402120', NULL, NULL, '2026-08-08 12:30:00', 'accepted', 'passed', '2026-08-08 11:30:00', '2026-08-18 11:30:00', '2026-08-22 11:30:00', NULL, '2026-09-22 09:30:00'),
('APP023','c37c59127a549e8f1a597a96b82f808c',1, 'Edwin  Bautista', 'Edwin', '', 'Bautista', 'edwinbautista',
   'edwin.bautista@example.com', 'edwin.bautista@example.com', '09404210734', NULL, NULL, '2026-08-08 13:40:00', 'pending', NULL, '2026-08-08 12:40:00', NULL, NULL, NULL, '2026-09-23 10:40:00'),
('APP024','c4c8f1b2fc3a3cd2d0550460f0f45e5e',1, 'Ernesto  Delgado', 'Ernesto', '', 'Delgado', 'ernestodelgado',
   'ernesto.delgado@example.com', 'ernesto.delgado@example.com', '09591468390', NULL, NULL, '2026-08-08 14:50:00', 'accepted', 'passed', '2026-08-08 13:50:00', '2026-08-20 13:50:00', '2026-08-26 13:50:00', NULL, '2026-09-24 11:50:00'),
('APP025','0088468425d0f2e3175a0f28595d3e1f',1, 'Fernando  Castro', 'Fernando', '', 'Castro', 'fernandocastro',
   'fernando.castro@example.com', 'fernando.castro@example.com', '09330852066', NULL, NULL, '2026-08-09 15:00:00', 'in_progress', NULL, '2026-08-09 14:00:00', NULL, NULL, NULL, '2026-09-25 12:00:00'),
('APP026','3f398faffba8fa24c5ffd1918b94ec22',1, 'Gregorio  Mendoza', 'Gregorio', '', 'Mendoza', 'gregoriomendoza',
   'gregorio.mendoza@example.com', 'gregorio.mendoza@example.com', '09756942619', NULL, NULL, '2026-08-09 16:10:00', 'pending', NULL, '2026-08-09 15:10:00', NULL, NULL, NULL, '2026-09-26 13:10:00'),
('APP027','db3eaa334ba81bf0abee2aa37ac469af',1, 'Hernando  Villanueva', 'Hernando', '', 'Villanueva', 'hernandovillanueva',
   'hernando.villanueva@example.com', 'hernando.villanueva@example.com', '09162566159', NULL, NULL, '2026-08-09 17:20:00', 'pending', NULL, '2026-08-09 16:20:00', NULL, NULL, NULL, '2026-09-27 14:20:00'),
('APP028','f89058a3f5eecf6b09f738d0df7c5f01',1, 'Ignacio  Santos', 'Ignacio', '', 'Santos', 'ignaciosantos',
   'ignacio.santos@example.com', 'ignacio.santos@example.com', '09239354506', NULL, NULL, '2026-08-09 09:30:00', 'rejected', 'rejected', '2026-08-09 08:30:00', '2026-08-12 08:30:00', '2026-08-16 08:30:00', NULL, '2026-10-28 15:30:00'),
('APP029','7c5c0d52600e574ff791e860b4f7dc41',1, 'Jaime  Reyes', 'Jaime', '', 'Reyes', 'jaimereyes',
   'jaime.reyes@example.com', 'jaime.reyes@example.com', '09680285582', NULL, NULL, '2026-08-10 10:40:00', 'accepted', 'passed', '2026-08-10 09:40:00', '2026-08-14 09:40:00', '2026-08-19 09:40:00', NULL, '2026-10-01 16:40:00'),
('APP030','48867758676fccd5ca3867d4cb716af1',1, 'Leandro  Cruz', 'Leandro', '', 'Cruz', 'leandrocruz',
   'leandro.cruz@example.com', 'leandro.cruz@example.com', '09358697453', NULL, NULL, '2026-08-10 11:50:00', 'pending', NULL, '2026-08-10 10:50:00', NULL, NULL, NULL, '2026-10-02 17:50:00'),
('APP031','d4e87806495175c52251fdd706796d0c',1, 'Leonardo  Garcia', 'Leonardo', '', 'Garcia', 'leonardogarcia',
   'leonardo.garcia@example.com', 'leonardo.garcia@example.com', '09834837631', NULL, NULL, '2026-08-10 12:00:00', 'rejected', 'rejected', '2026-08-10 11:00:00', '2026-08-16 11:00:00', '2026-08-17 11:00:00', NULL, '2026-10-03 08:00:00'),
('APP032','253d0ab8e771c1d6c89debbac4374a60',1, 'Marcelo  Martinez', 'Marcelo', '', 'Martinez', 'marcelomartinez',
   'marcelo.martinez@example.com', 'marcelo.martinez@example.com', '09394340167', NULL, NULL, '2026-08-10 13:10:00', 'rejected', 'rejected', '2026-08-10 12:10:00', '2026-08-17 12:10:00', '2026-08-19 12:10:00', NULL, '2026-10-04 09:10:00'),
('APP033','6f05fe038189909d4731e65773d44e41',1, 'Nelson  Fernandez', 'Nelson', '', 'Fernandez', 'nelsonfernandez',
   'nelson.fernandez@example.com', 'nelson.fernandez@example.com', '09218066941', NULL, NULL, '2026-08-11 14:20:00', 'rejected', 'rejected', '2026-08-11 13:20:00', '2026-08-19 13:20:00', '2026-08-22 13:20:00', NULL, '2026-10-05 10:20:00'),
('APP034','465d80a2eebed2bd1c89de2094950274',1, 'Orlando  Lopez', 'Orlando', '', 'Lopez', 'orlandolopez',
   'orlando.lopez@example.com', 'orlando.lopez@example.com', '09689401572', NULL, NULL, '2026-08-11 15:30:00', 'pending', NULL, '2026-08-11 14:30:00', NULL, NULL, NULL, '2026-10-06 11:30:00'),
('APP035','82f2f456c676713f718c0ae21b7fdfa2',1, 'Oscar  Gonzalez', 'Oscar', '', 'Gonzalez', 'oscargonzalez',
   'oscar.gonzalez@example.com', 'oscar.gonzalez@example.com', '09718625570', NULL, NULL, '2026-08-11 16:40:00', 'in_progress', NULL, '2026-08-11 15:40:00', NULL, NULL, NULL, '2026-10-07 12:40:00'),
('APP036','c281849cd40f01b384ae2c9426670050',1, 'Rolando  Perez', 'Rolando', '', 'Perez', 'rolandoperez',
   'rolando.perez@example.com', 'rolando.perez@example.com', '09783740088', NULL, NULL, '2026-08-11 17:50:00', 'rejected', 'rejected', '2026-08-11 16:50:00', '2026-08-22 16:50:00', '2026-08-28 16:50:00', NULL, '2026-10-08 13:50:00'),
('APP037','96909b0aedfccf245ad9f35e60a7e567',1, 'Romeo  Sanchez', 'Romeo', '', 'Sanchez', 'romeosanchez',
   'romeo.sanchez@example.com', 'romeo.sanchez@example.com', '09714338821', NULL, NULL, '2026-08-12 09:00:00', 'accepted', 'passed', '2026-08-12 08:00:00', '2026-08-24 08:00:00', '2026-08-25 08:00:00', NULL, '2026-10-09 14:00:00'),
('APP038','4a55f9925031d302979e5f366619d94e',1, 'Salvador  Ramirez', 'Salvador', '', 'Ramirez', 'salvadorramirez',
   'salvador.ramirez@example.com', 'salvador.ramirez@example.com', '09959669269', NULL, NULL, '2026-08-12 10:10:00', 'in_progress', NULL, '2026-08-12 09:10:00', NULL, NULL, NULL, '2026-10-10 15:10:00'),
('APP039','9c40665c1cdae47980603394c4cb3564',1, 'Teodoro  Torres', 'Teodoro', '', 'Torres', 'teodorotorres',
   'teodoro.torres@example.com', 'teodoro.torres@example.com', '09487530378', NULL, NULL, '2026-08-12 11:20:00', 'in_progress', NULL, '2026-08-12 10:20:00', NULL, NULL, NULL, '2026-10-11 16:20:00'),
('APP040','30f3d04f56b8cd582e7226d873b4eaf6',1, 'Victor  Flores', 'Victor', '', 'Flores', 'victorflores',
   'victor.flores@example.com', 'victor.flores@example.com', '09998845806', NULL, NULL, '2026-08-12 12:30:00', 'in_progress', NULL, '2026-08-12 11:30:00', NULL, NULL, NULL, '2026-11-03 17:30:00'),
('APP041','bd983f5465132cbc0f55d3fdcdfb8d0e',1, 'Wilfredo  Rivera', 'Wilfredo', '', 'Rivera', 'wilfredorivera',
   'wilfredo.rivera@example.com', 'wilfredo.rivera@example.com', '09290802044', NULL, NULL, '2026-08-13 13:40:00', 'accepted', 'passed', '2026-08-13 12:40:00', '2026-08-16 12:40:00', '2026-08-21 12:40:00', NULL, '2026-11-06 08:40:00'),
('APP042','e1bdbb013455935fc7485a76d178066d',1, 'Beverly  Gomez', 'Beverly', '', 'Gomez', 'beverlygomez',
   'beverly.gomez@example.com', 'beverly.gomez@example.com', '09448039232', NULL, NULL, '2026-08-13 14:50:00', 'in_progress', NULL, '2026-08-13 13:50:00', NULL, NULL, NULL, '2026-11-10 09:50:00'),
('APP043','9f0bef27f6c9e8269a3790bb05dfb0ce',1, 'Cynthia  Diaz', 'Cynthia', '', 'Diaz', 'cynthiadiaz',
   'cynthia.diaz@example.com', 'cynthia.diaz@example.com', '09423245249', NULL, NULL, '2026-08-13 15:00:00', 'accepted', 'passed', '2026-08-13 14:00:00', '2026-08-18 14:00:00', '2026-08-19 14:00:00', NULL, '2026-11-13 10:00:00'),
('APP044','5cb7c419a85ab821c9a082ae92900e7e',1, 'Daisy  Morales', 'Daisy', '', 'Morales', 'daisymorales',
   'daisy.morales@example.com', 'daisy.morales@example.com', '09759385271', NULL, NULL, '2026-08-13 16:10:00', 'accepted', 'passed', '2026-08-13 15:10:00', '2026-08-19 15:10:00', '2026-08-21 15:10:00', NULL, '2026-11-17 11:10:00'),
('APP045','8a0a8de4dda74f2fabc0ce807b1bdef1',1, 'Elizabeth  Gutierrez', 'Elizabeth', '', 'Gutierrez', 'elizabethgutierrez',
   'elizabeth.gutierrez@example.com', 'elizabeth.gutierrez@example.com', '09442142860', NULL, NULL, '2026-08-14 17:20:00', 'pending', NULL, '2026-08-14 16:20:00', NULL, NULL, NULL, '2026-11-20 12:20:00'),
('APP046','8275aee0487acfc701d435d2b63fb24d',1, 'Flora  Ramos', 'Flora', '', 'Ramos', 'floraramos',
   'flora.ramos@example.com', 'flora.ramos@example.com', '09585045800', NULL, NULL, '2026-08-14 09:30:00', 'rejected', 'rejected', '2026-08-14 08:30:00', '2026-08-22 08:30:00', '2026-08-26 08:30:00', NULL, '2026-11-24 13:30:00'),
('APP047','d7e5168a94189ad3e0386400f10cc975',1, 'Helen  Aquino', 'Helen', '', 'Aquino', 'helenaquino',
   'helen.aquino@example.com', 'helen.aquino@example.com', '09122999358', NULL, NULL, '2026-08-14 10:40:00', 'accepted', 'passed', '2026-08-14 09:40:00', '2026-08-23 09:40:00', '2026-08-28 09:40:00', NULL, '2026-11-27 14:40:00'),
('APP048','c60495df8604342171a0e1a54022e747',1, 'Imelda  Bautista', 'Imelda', '', 'Bautista', 'imeldabautista',
   'imelda.bautista@example.com', 'imelda.bautista@example.com', '09967838707', NULL, NULL, '2026-08-14 11:50:00', 'pending', NULL, '2026-08-14 10:50:00', NULL, NULL, NULL, '2026-11-03 15:50:00'),
('APP049','202b14673a8a05ffe154d225b142d066',1, 'Janet  Delgado', 'Janet', '', 'Delgado', 'janetdelgado',
   'janet.delgado@example.com', 'janet.delgado@example.com', '09919554194', NULL, NULL, '2026-08-15 12:00:00', 'accepted', 'passed', '2026-08-15 11:00:00', '2026-08-26 11:00:00', '2026-08-27 11:00:00', NULL, '2026-11-06 16:00:00'),
('APP050','770d6094dae3dc3990273c0f79acbedf',1, 'Kristine  Castro', 'Kristine', '', 'Castro', 'kristinecastro',
   'kristine.castro@example.com', 'kristine.castro@example.com', '09183265983', NULL, NULL, '2026-08-15 13:10:00', 'pending', NULL, '2026-08-15 12:10:00', NULL, NULL, NULL, '2026-11-10 17:10:00');

-- ============================================================
INSERT INTO schedule (applicant_id, preferred_date, preferred_time, final_date, final_time, venue, status, email_sent_at, answered_at, created_at, updated_at) VALUES
(17, '2026-08-25', '11:00:00', '2026-08-25', '11:00:00', 'Conference Room B', 'confirmed', '2026-08-24 11:00:00', '2026-08-25 11:00:00', NOW(), NOW()),
(24, '2026-09-01', '11:00:00', '2026-09-01', '11:00:00', 'Conference Room C', 'confirmed', '2026-08-31 11:00:00', '2026-09-01 11:00:00', NOW(), NOW()),
(29, '2026-08-21', '09:00:00', '2026-08-21', '09:00:00', 'Conference Room B', 'confirmed', '2026-08-20 09:00:00', '2026-08-21 09:00:00', NOW(), NOW()),
(41, '2026-08-26', '14:00:00', '2026-08-26', '14:00:00', 'Conference Room B', 'confirmed', '2026-08-25 14:00:00', '2026-08-26 14:00:00', NOW(), NOW()),
(44, '2026-08-29', '10:00:00', '2026-08-29', '10:00:00', 'Conference Room B', 'confirmed', '2026-08-28 10:00:00', '2026-08-29 10:00:00', NOW(), NOW()),
(47, '2026-08-30', '13:00:00', '2026-08-30', '13:00:00', 'Conference Room B', 'confirmed', '2026-08-29 13:00:00', '2026-08-30 13:00:00', NOW(), NOW());



-- QUESTIONS
-- 13 questions across 5 sections.
-- ============================================================
INSERT INTO question_config
    (section_no, display_order, section, question_text, question_type, answer_config, passing_answer, is_knockout, is_consent, is_data_protection, reminder_text)
VALUES

-- ------------------------------------------------------------
-- Section 1: Consent & Readiness
-- ------------------------------------------------------------
(1, 0, 'Consent & Readiness',
    'Have you read the {link} thoroughly?',
    'yesno',
    jsonb_build_object(
        'no_note', 'Please read the job description first. The link above opens it in a new tab.',
        'link',    jsonb_build_object(
                       'text', 'job description',
                       'url',  'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf'
                   )
    ),
    NULL,
    false, false, false, NULL),

(1, 1, 'Consent & Readiness',
    'Are you willing to work on the schedule as advertised?',
    'yesno',
    NULL,
    jsonb_build_object('answer', 'Yes'),
    true, false, false, NULL),

(1, 2, 'Consent & Readiness',
    'Are you willing to work on-site in BGC, Taguig?',
    'yesno',
    NULL,
    jsonb_build_object('answer', 'Yes'),
    true, false, false, 'Please note this may affect your application.'),

(1, 3, 'Consent & Readiness',
    'Are you willing to go through the assessment and interviews?',
    'yesno',
    NULL,
    jsonb_build_object('answer', 'Yes'),
    true, false, false, NULL),

(1, 4, 'Consent & Readiness',
    'Rate your interest level in pursuing your application.',
    'scale',
    jsonb_build_object(
        'max',    5,
        'labels', jsonb_build_array('Not very interested', 'Extremely interested')
    ),
    NULL,
    false, false, false, NULL),

-- ------------------------------------------------------------
-- Section 2: Logistics & Compliance
-- ------------------------------------------------------------
(2, 0, 'Logistics & Compliance',
    'When is your earliest possible start date?',
    'date',
    jsonb_build_object('hint', 'Choose a date'),
    NULL,
    false, false, false, NULL),

(2, 1, 'Logistics & Compliance',
    'Are you currently employed? If yes, what is your notice period?',
    'yesno_select',
    jsonb_build_object(
        'followup_when',    'Yes',
        'followup_label',   'Your notice period',
        'followup_options', jsonb_build_array(
                                'Less than 1 week',
                                '1 to 2 weeks',
                                '3 to 4 weeks (30 days)',
                                '1 to 2 months',
                                'More than 2 months'
                            )
    ),
    NULL,
    false, false, false, NULL),

(2, 2, 'Logistics & Compliance',
    'Do you have access to a reliable internet connection and a device suitable for completing the assessment and virtual steps?',
    'yesno',
    jsonb_build_object(
        'no_note', 'A stable connection and a working device are needed for the assessment and the virtual steps.'
    ),
    jsonb_build_object('answer', 'Yes'),
    true, false, false, NULL),

(2, 3, 'Logistics & Compliance',
    'Are you willing to undergo a background check as part of the hiring process?',
    'yesno',
    NULL,
    jsonb_build_object('answer', 'Yes'),
    true, false, false, NULL),

(2, 4, 'Logistics & Compliance',
    'Can you provide required pre-employment documents (e.g. NBI clearance, TIN, SSS) upon request?',
    'yesno',
    NULL,
    jsonb_build_object('answer', 'Yes'),
    true, false, false, NULL),

-- ------------------------------------------------------------
-- Section 3: Confidentiality Acknowledgment
-- ------------------------------------------------------------
(3, 0, 'Confidentiality Acknowledgment',
    'This role involves handling sensitive client information. Do you agree to comply with confidentiality and data protection requirements?',
    'yesno',
    jsonb_build_object(
        'no_note', 'Complying with confidentiality and data protection requirements is required for this role.'
    ),
    jsonb_build_object('answer', 'Yes'),
    true, false, true, NULL),

-- ------------------------------------------------------------
-- Section 4: Comprehension
-- ------------------------------------------------------------
(4, 0, 'Comprehension',
    'In 2-3 sentences, describe what you understand this role to involve based on the job posting.',
    'text',
    jsonb_build_object(
        'hint',        'Write it in your own words.',
        'placeholder', 'Type your answer here...',
        'min_chars',   30,
        'max_chars',   500
    ),
    NULL,
    false, false, false, NULL),

-- ------------------------------------------------------------
-- Section 5: Data Protection
-- ------------------------------------------------------------
(5, 0, 'Data Protection',
    'Please read and accept the data protection statement.',
    'consent',
    jsonb_build_object(
        'body', jsonb_build_array(
            'By submitting your application, you agree that Expertise Company will use your data by collecting, processing, storing, and using your personal information for legitimate recruitment and employment purposes only, including applicant screening, assessment, interview scheduling, and communication. Your information will be kept confidential and secure and will only be accessed by authorized personnel or disclosed when required or permitted by law.',
            'You have the right to access, correct, and request the deletion or restriction of your personal information, subject to applicable laws and regulations.',
            'In accordance with the Data Privacy Act of 2012 (Republic Act No. 10173), The Company is committed to protecting the privacy and confidentiality of your personal information. Rest assured that Expertise will not use your personal information for purposes unrelated to recruitment without obtaining the appropriate consent or having another lawful basis for such processing.',
            'To keep the screening fair and secure, we also collect technical information such as your device identifier and IP address, and how you move through the screening (for example, the time spent on each question). This information is used only to protect the integrity of the screening and is reviewed by authorized personnel.'
        ),
        'checkbox', 'I have read and agree to the data protection statement.'
    ),
    NULL,
    false, true, true, 'You must agree to proceed.');

-- ============================================================
-- INTERVIEW SCHEDULES
-- Confirmed schedules for passed applicants (drives the
-- Scheduling Turnaround Time card).
-- answered_at must be >= passed_at to count as scheduled.
-- ============================================================
INSERT INTO schedule (applicant_id, preferred_date, preferred_time, final_date, final_time, venue, status, email_sent_at, answered_at, created_at, updated_at) VALUES
(1, '2026-10-02', '10:00:00', '2026-10-02', '10:00:00', 'Conference Room A', 'confirmed', '2026-10-01 08:00:00', '2026-10-02 10:00:00', NOW(), NOW()),
(5, '2026-10-24', '14:00:00', '2026-10-24', '14:00:00', 'Conference Room B', 'confirmed', '2026-10-22 08:00:00', '2026-10-24 14:00:00', NOW(), NOW()),
(6, '2026-10-28', '09:00:00', '2026-10-28', '09:00:00', 'Conference Room C', 'confirmed', '2026-10-24 08:00:00', '2026-10-28 09:00:00', NOW(), NOW());

-- ============================================================
-- QUESTIONNAIRE RESPONSES
-- Submitted questionnaire answers (drives Admin Questionnaire Statistics card).
-- One row per applicant who completed the screening questionnaire.
-- ============================================================
INSERT INTO response (applicant_id, answers_json, timing_json, started_at, submitted_at) VALUES
(1, '{"q1":true,"q2":true,"q3":true,"q4":false,"q5":3,"q6":"2026-09-27","q7":true,"q8":true,"q9":true,"q10":true,"q11":true,"q12":"The role is to process recruitment applications, verify documents, and coordinate interviews for qualified candidates.","q13":true}'::jsonb, '{}'::jsonb, '2026-10-06 00:00:00', '2026-10-06 00:00:00'),
(2, '{"q1":true,"q2":true,"q3":true,"q4":false,"q5":3,"q6":"2026-10-02","q7":true,"q8":false,"q9":false,"q10":true,"q11":false,"q12":"I understand this role involves handling client screening, assessments, and interviews while maintaining confidentiality of sensitive information.","q13":true}'::jsonb, '{}'::jsonb, '2026-10-05 00:00:00', '2026-10-05 00:00:00'),
(3, '{"q1":true,"q2":true,"q3":true,"q4":false,"q5":4,"q6":"2026-10-25","q7":true,"q8":false,"q9":true,"q10":false,"q11":true,"q12":"This role involves managing the end-to-end applicant screening process from application to interview scheduling.","q13":true}'::jsonb, '{}'::jsonb, '2026-10-07 00:00:00', '2026-10-07 00:00:00'),
(5, '{"q1":false,"q2":false,"q3":true,"q4":false,"q5":4,"q6":"2026-10-30","q7":true,"q8":true,"q9":true,"q10":true,"q11":false,"q12":"I believe this role involves evaluating candidate qualifications, scheduling assessments, and ensuring data privacy compliance.","q13":true}'::jsonb, '{}'::jsonb, '2026-08-16 12:40:00', '2026-08-16 12:40:00'),
(6, '{"q1":true,"q2":true,"q3":false,"q4":true,"q5":5,"q6":"2026-10-30","q7":true,"q8":false,"q9":false,"q10":true,"q11":true,"q12":"I understand this role involves handling client screening, assessments, and interviews while maintaining confidentiality of sensitive information.","q13":true}'::jsonb, '{}'::jsonb, '2026-08-22 13:50:00', '2026-08-22 13:50:00'),
(9, '{"q1":true,"q2":true,"q3":true,"q4":false,"q5":3,"q6":"2026-09-15","q7":true,"q8":false,"q9":true,"q10":true,"q11":false,"q12":"I believe this role involves evaluating candidate qualifications, scheduling assessments, and ensuring data privacy compliance.","q13":true}'::jsonb, '{}'::jsonb, '2026-08-19 16:20:00', '2026-08-19 16:20:00'),
(10, '{"q1":true,"q2":true,"q3":true,"q4":true,"q5":3,"q6":"2026-10-28","q7":true,"q8":false,"q9":false,"q10":true,"q11":false,"q12":"The role is to process recruitment applications, verify documents, and coordinate interviews for qualified candidates.","q13":true}'::jsonb, '{}'::jsonb, '2026-10-05 00:00:00', '2026-10-05 00:00:00'),
(13, '{"q1":true,"q2":false,"q3":false,"q4":true,"q5":4,"q6":"2026-09-23","q7":true,"q8":true,"q9":false,"q10":true,"q11":true,"q12":"This role involves managing the end-to-end applicant screening process from application to interview scheduling.","q13":true}'::jsonb, '{}'::jsonb, '2026-08-24 11:00:00', '2026-08-24 11:00:00'),
(14, '{"q1":true,"q2":true,"q3":true,"q4":false,"q5":4,"q6":"2026-09-01","q7":true,"q8":false,"q9":true,"q10":true,"q11":false,"q12":"Based on the job posting, this position involves screening applicants, conducting background checks, and supporting the hiring team.","q13":true}'::jsonb, '{}'::jsonb, '2026-08-13 12:10:00', '2026-08-13 12:10:00'),
(15, '{"q1":true,"q2":true,"q3":false,"q4":true,"q5":5,"q6":"2026-09-13","q7":true,"q8":true,"q9":true,"q10":true,"q11":true,"q12":"I believe this role involves evaluating candidate qualifications, scheduling assessments, and ensuring data privacy compliance.","q13":true}'::jsonb, '{}'::jsonb, '2026-10-05 00:00:00', '2026-10-05 00:00:00'),
(18, '{"q1":true,"q2":true,"q3":true,"q4":true,"q5":4,"q6":"2026-10-18","q7":false,"q8":true,"q9":true,"q10":true,"q11":false,"q12":"I understand this role involves handling client screening, assessments, and interviews while maintaining confidentiality of sensitive information.","q13":true}'::jsonb, '{}'::jsonb, '2026-10-05 00:00:00', '2026-10-05 00:00:00'),
(19, '{"q1":false,"q2":true,"q3":false,"q4":true,"q5":2,"q6":"2026-09-24","q7":true,"q8":true,"q9":true,"q10":true,"q11":true,"q12":"Based on the job posting, this position involves screening applicants, conducting background checks, and supporting the hiring team.","q13":true}'::jsonb, '{}'::jsonb, '2026-08-17 08:00:00', '2026-08-17 08:00:00'),
(21, '{"q1":true,"q2":true,"q3":true,"q4":true,"q5":3,"q6":"2026-10-16","q7":false,"q8":true,"q9":true,"q10":true,"q11":true,"q12":"Based on the job posting, this position involves screening applicants, conducting background checks, and supporting the hiring team.","q13":true}'::jsonb, '{}'::jsonb, '2026-10-02 00:00:00', '2026-10-02 00:00:00'),
(22, '{"q1":false,"q2":true,"q3":false,"q4":false,"q5":2,"q6":"2026-10-15","q7":true,"q8":true,"q9":true,"q10":false,"q11":true,"q12":"Based on the job posting, this position involves screening applicants, conducting background checks, and supporting the hiring team.","q13":true}'::jsonb, '{}'::jsonb, '2026-08-26 11:30:00', '2026-08-26 11:30:00'),
(23, '{"q1":false,"q2":true,"q3":true,"q4":true,"q5":2,"q6":"2026-10-06","q7":true,"q8":true,"q9":false,"q10":true,"q11":true,"q12":"I believe this role involves evaluating candidate qualifications, scheduling assessments, and ensuring data privacy compliance.","q13":true}'::jsonb, '{}'::jsonb, '2026-10-07 00:00:00', '2026-10-07 00:00:00'),
(25, '{"q1":true,"q2":true,"q3":true,"q4":true,"q5":2,"q6":"2026-09-01","q7":true,"q8":true,"q9":true,"q10":true,"q11":true,"q12":"This role involves managing the end-to-end applicant screening process from application to interview scheduling.","q13":true}'::jsonb, '{}'::jsonb, '2026-10-03 00:00:00', '2026-10-03 00:00:00'),
(26, '{"q1":true,"q2":true,"q3":true,"q4":false,"q5":4,"q6":"2026-10-22","q7":true,"q8":false,"q9":false,"q10":true,"q11":true,"q12":"The role is to process recruitment applications, verify documents, and coordinate interviews for qualified candidates.","q13":true}'::jsonb, '{}'::jsonb, '2026-10-02 00:00:00', '2026-10-02 00:00:00'),
(29, '{"q1":true,"q2":true,"q3":true,"q4":false,"q5":4,"q6":"2026-09-11","q7":true,"q8":true,"q9":true,"q10":true,"q11":true,"q12":"Based on the job posting, this position involves screening applicants, conducting background checks, and supporting the hiring team.","q13":true}'::jsonb, '{}'::jsonb, '2026-08-22 09:40:00', '2026-08-22 09:40:00'),
(30, '{"q1":true,"q2":true,"q3":true,"q4":true,"q5":2,"q6":"2026-09-22","q7":true,"q8":false,"q9":true,"q10":false,"q11":true,"q12":"Based on the job posting, this position involves screening applicants, conducting background checks, and supporting the hiring team.","q13":true}'::jsonb, '{}'::jsonb, '2026-10-02 00:00:00', '2026-10-02 00:00:00'),
(31, '{"q1":true,"q2":false,"q3":true,"q4":false,"q5":3,"q6":"2026-10-09","q7":false,"q8":true,"q9":false,"q10":true,"q11":false,"q12":"The role is to process recruitment applications, verify documents, and coordinate interviews for qualified candidates.","q13":true}'::jsonb, '{}'::jsonb, '2026-08-23 11:00:00', '2026-08-23 11:00:00'),
(32, '{"q1":false,"q2":false,"q3":false,"q4":false,"q5":5,"q6":"2026-10-09","q7":true,"q8":true,"q9":true,"q10":true,"q11":true,"q12":"Based on the job posting, this position involves screening applicants, conducting background checks, and supporting the hiring team.","q13":true}'::jsonb, '{}'::jsonb, '2026-08-24 12:10:00', '2026-08-24 12:10:00'),
(35, '{"q1":true,"q2":false,"q3":true,"q4":true,"q5":2,"q6":"2026-10-28","q7":false,"q8":false,"q9":false,"q10":true,"q11":true,"q12":"Based on the job posting, this position involves screening applicants, conducting background checks, and supporting the hiring team.","q13":true}'::jsonb, '{}'::jsonb, '2026-10-05 00:00:00', '2026-10-05 00:00:00'),
(37, '{"q1":false,"q2":true,"q3":true,"q4":true,"q5":1,"q6":"2026-10-14","q7":false,"q8":true,"q9":true,"q10":true,"q11":true,"q12":"I understand this role involves handling client screening, assessments, and interviews while maintaining confidentiality of sensitive information.","q13":true}'::jsonb, '{}'::jsonb, '2026-08-29 08:00:00', '2026-08-29 08:00:00'),
(38, '{"q1":true,"q2":true,"q3":true,"q4":true,"q5":1,"q6":"2026-09-21","q7":true,"q8":true,"q9":false,"q10":true,"q11":true,"q12":"This role involves managing the end-to-end applicant screening process from application to interview scheduling.","q13":true}'::jsonb, '{}'::jsonb, '2026-10-03 00:00:00', '2026-10-03 00:00:00'),
(40, '{"q1":false,"q2":false,"q3":false,"q4":false,"q5":2,"q6":"2026-10-03","q7":false,"q8":false,"q9":true,"q10":true,"q11":true,"q12":"This role involves managing the end-to-end applicant screening process from application to interview scheduling.","q13":true}'::jsonb, '{}'::jsonb, '2026-10-03 00:00:00', '2026-10-03 00:00:00'),
(41, '{"q1":true,"q2":true,"q3":true,"q4":true,"q5":5,"q6":"2026-10-10","q7":false,"q8":true,"q9":true,"q10":true,"q11":true,"q12":"Based on the job posting, this position involves screening applicants, conducting background checks, and supporting the hiring team.","q13":true}'::jsonb, '{}'::jsonb, '2026-08-26 12:40:00', '2026-08-26 12:40:00'),
(42, '{"q1":false,"q2":true,"q3":true,"q4":false,"q5":1,"q6":"2026-09-13","q7":true,"q8":true,"q9":true,"q10":true,"q11":false,"q12":"This role involves managing the end-to-end applicant screening process from application to interview scheduling.","q13":true}'::jsonb, '{}'::jsonb, '2026-10-07 00:00:00', '2026-10-07 00:00:00'),
(45, '{"q1":true,"q2":true,"q3":true,"q4":true,"q5":3,"q6":"2026-09-15","q7":false,"q8":false,"q9":true,"q10":true,"q11":true,"q12":"This role involves managing the end-to-end applicant screening process from application to interview scheduling.","q13":true}'::jsonb, '{}'::jsonb, '2026-10-04 00:00:00', '2026-10-04 00:00:00'),
(47, '{"q1":true,"q2":true,"q3":false,"q4":false,"q5":1,"q6":"2026-10-06","q7":false,"q8":true,"q9":true,"q10":true,"q11":true,"q12":"I understand this role involves handling client screening, assessments, and interviews while maintaining confidentiality of sensitive information.","q13":true}'::jsonb, '{}'::jsonb, '2026-08-31 09:40:00', '2026-08-31 09:40:00'),
(48, '{"q1":false,"q2":false,"q3":true,"q4":false,"q5":1,"q6":"2026-09-23","q7":false,"q8":false,"q9":true,"q10":false,"q11":true,"q12":"Based on the job posting, this position involves screening applicants, conducting background checks, and supporting the hiring team.","q13":true}'::jsonb, '{}'::jsonb, '2026-10-07 00:00:00', '2026-10-07 00:00:00'),
(49, '{"q1":true,"q2":true,"q3":true,"q4":false,"q5":3,"q6":"2026-09-16","q7":false,"q8":true,"q9":true,"q10":true,"q11":true,"q12":"This role involves managing the end-to-end applicant screening process from application to interview scheduling.","q13":true}'::jsonb, '{}'::jsonb, '2026-09-02 11:00:00', '2026-09-02 11:00:00');

-- ============================================================
-- RISK SCORES
-- Risk evaluation scores (drives Admin Risk Flag Breakdown by Factor card).
-- One row per submitted questionnaire response.
-- ============================================================
INSERT INTO score (response_id, risk_score, risk_flags_json, narrative_json, final_decision, reviewed_at) VALUES
(1, 4.00, '{"band":"Medium","factors":[{"max":2,"label":"Failed OTP attempts","points":1,"reason":"One wrong code before success."},{"max":3,"label":"Questionnaire speed","points":0,"reason":"Normal completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":3,"reason":"Video matches another applicant."}],"auto_high":["Video matches another applicant."]}'::jsonb, '{"summary":"Risk evaluation for applicant 1"}'::jsonb, 'pending', 2026-11-14 01:49:58.05058),
(2, 5.00, '{"band":"Medium","factors":[{"max":2,"label":"Failed OTP attempts","points":0,"reason":"No failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":3,"reason":"Completed questionnaire suspiciously fast."},{"max":3,"label":"Same device or IP as other applicants","points":2,"reason":"Same device as 1 other applicant."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":["Questionnaire completed suspiciously fast."]}'::jsonb, '{"summary":"Risk evaluation for applicant 2"}'::jsonb, 'pending', 2026-11-14 01:49:58.057952),
(3, 2.00, '{"band":"Low","factors":[{"max":2,"label":"Failed OTP attempts","points":0,"reason":"No failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":0,"reason":"Normal completion time."},{"max":3,"label":"Same device or IP as other applicants","points":1,"reason":"Possible device overlap."},{"max":3,"label":"Video matches another applicant","points":1,"reason":"Possible video similarity."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 3"}'::jsonb, 'pending', 2026-11-14 01:49:58.058958),
(4, 0.00, '{"band":"Low","factors":[{"max":2,"label":"Failed OTP attempts","points":0,"reason":"No failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":0,"reason":"Normal completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 5"}'::jsonb, 'pending', 2026-11-14 01:49:58.05994),
(5, 2.00, '{"band":"Low","factors":[{"max":2,"label":"Failed OTP attempts","points":0,"reason":"No failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":2,"reason":"Fast completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 6"}'::jsonb, 'pending', 2026-11-14 01:49:58.060853),
(6, 3.00, '{"band":"Medium","factors":[{"max":2,"label":"Failed OTP attempts","points":1,"reason":"One wrong code before success."},{"max":3,"label":"Questionnaire speed","points":1,"reason":"Slightly fast completion time."},{"max":3,"label":"Same device or IP as other applicants","points":1,"reason":"Possible device overlap."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 9"}'::jsonb, 'pending', 2026-11-14 01:49:58.06172),
(7, 1.00, '{"band":"Low","factors":[{"max":2,"label":"Failed OTP attempts","points":0,"reason":"No failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":1,"reason":"Slightly fast completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 10"}'::jsonb, 'pending', 2026-11-14 01:49:58.062622),
(8, 0.00, '{"band":"Low","factors":[{"max":2,"label":"Failed OTP attempts","points":0,"reason":"No failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":0,"reason":"Normal completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 13"}'::jsonb, 'pending', 2026-11-14 01:49:58.063403),
(9, 7.00, '{"band":"High","factors":[{"max":2,"label":"Failed OTP attempts","points":2,"reason":"Multiple failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":2,"reason":"Fast completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":3,"reason":"Video matches another applicant."}],"auto_high":["Multiple failed OTP attempts.","Video matches another applicant."]}'::jsonb, '{"summary":"Risk evaluation for applicant 14"}'::jsonb, 'manual_review', 2026-11-14 01:49:58.064364),
(10, 7.00, '{"band":"High","factors":[{"max":2,"label":"Failed OTP attempts","points":0,"reason":"No failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":2,"reason":"Fast completion time."},{"max":3,"label":"Same device or IP as other applicants","points":2,"reason":"Same device as 1 other applicant."},{"max":3,"label":"Video matches another applicant","points":3,"reason":"Video matches another applicant."}],"auto_high":["Video matches another applicant."]}'::jsonb, '{"summary":"Risk evaluation for applicant 15"}'::jsonb, 'manual_review', 2026-11-14 01:49:58.065309),
(11, 2.00, '{"band":"Low","factors":[{"max":2,"label":"Failed OTP attempts","points":2,"reason":"Multiple failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":0,"reason":"Normal completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":["Multiple failed OTP attempts."]}'::jsonb, '{"summary":"Risk evaluation for applicant 18"}'::jsonb, 'pending', 2026-11-14 01:49:58.066369),
(12, 3.00, '{"band":"Medium","factors":[{"max":2,"label":"OTP requests in 15 minutes","points":1,"reason":"Several OTP requests in a short window."},{"max":3,"label":"Questionnaire speed","points":1,"reason":"Slightly fast completion time."},{"max":3,"label":"Same device or IP as other applicants","points":1,"reason":"Possible device overlap."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 19"}'::jsonb, 'pending', 2026-11-14 01:49:58.067353),
(13, 0.00, '{"band":"Low","factors":[{"max":2,"label":"Failed OTP attempts","points":0,"reason":"No failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":0,"reason":"Normal completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 21"}'::jsonb, 'pending', 2026-11-14 01:49:58.070121),
(14, 3.00, '{"band":"Medium","factors":[{"max":2,"label":"Failed OTP attempts","points":1,"reason":"One wrong code before success."},{"max":3,"label":"Questionnaire speed","points":2,"reason":"Fast completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 22"}'::jsonb, 'pending', 2026-11-14 01:49:58.071845),
(15, 0.00, '{"band":"Low","factors":[{"max":2,"label":"Failed OTP attempts","points":0,"reason":"No failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":0,"reason":"Normal completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 23"}'::jsonb, 'pending', 2026-11-14 01:49:58.073201),
(16, 3.00, '{"band":"Medium","factors":[{"max":2,"label":"Failed OTP attempts","points":0,"reason":"No failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":2,"reason":"Fast completion time."},{"max":3,"label":"Same device or IP as other applicants","points":1,"reason":"Possible device overlap."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 25"}'::jsonb, 'pending', 2026-11-14 01:49:58.074156),
(17, 4.00, '{"band":"Medium","factors":[{"max":2,"label":"Failed OTP attempts","points":0,"reason":"No failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":1,"reason":"Slightly fast completion time."},{"max":3,"label":"Same device or IP as other applicants","points":3,"reason":"Same device as 2 or more other applicants."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":["Same device as multiple applicants."]}'::jsonb, '{"summary":"Risk evaluation for applicant 26"}'::jsonb, 'pending', 2026-11-14 01:49:58.075037),
(18, 1.00, '{"band":"Low","factors":[{"max":2,"label":"OTP requests in 15 minutes","points":1,"reason":"Several OTP requests in a short window."},{"max":3,"label":"Questionnaire speed","points":0,"reason":"Normal completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 29"}'::jsonb, 'pending', 2026-11-14 01:49:58.076051),
(19, 0.00, '{"band":"Low","factors":[{"max":2,"label":"Failed OTP attempts","points":0,"reason":"No failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":0,"reason":"Normal completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 30"}'::jsonb, 'pending', 2026-11-14 01:49:58.076972),
(20, 0.00, '{"band":"Low","factors":[{"max":2,"label":"Failed OTP attempts","points":0,"reason":"No failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":0,"reason":"Normal completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 31"}'::jsonb, 'pending', 2026-11-14 01:49:58.077881),
(21, 0.00, '{"band":"Low","factors":[{"max":2,"label":"Failed OTP attempts","points":0,"reason":"No failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":0,"reason":"Normal completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 32"}'::jsonb, 'pending', 2026-11-14 01:49:58.078609),
(22, 0.00, '{"band":"Low","factors":[{"max":2,"label":"Failed OTP attempts","points":0,"reason":"No failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":0,"reason":"Normal completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 35"}'::jsonb, 'pending', 2026-11-14 01:49:58.079244),
(23, 3.00, '{"band":"Medium","factors":[{"max":2,"label":"OTP requests in 15 minutes","points":1,"reason":"Several OTP requests in a short window."},{"max":3,"label":"Questionnaire speed","points":2,"reason":"Fast completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 37"}'::jsonb, 'pending', 2026-11-14 01:49:58.08039),
(24, 1.00, '{"band":"Low","factors":[{"max":2,"label":"Failed OTP attempts","points":0,"reason":"No failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":0,"reason":"Normal completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":1,"reason":"Possible video similarity."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 38"}'::jsonb, 'pending', 2026-11-14 01:49:58.081027),
(25, 3.00, '{"band":"Medium","factors":[{"max":2,"label":"Failed OTP attempts","points":1,"reason":"One wrong code before success."},{"max":3,"label":"Questionnaire speed","points":2,"reason":"Fast completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 40"}'::jsonb, 'pending', 2026-11-14 01:49:58.081625),
(26, 1.00, '{"band":"Low","factors":[{"max":2,"label":"OTP requests in 15 minutes","points":1,"reason":"Several OTP requests in a short window."},{"max":3,"label":"Questionnaire speed","points":0,"reason":"Normal completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 41"}'::jsonb, 'pending', 2026-11-14 01:49:58.082297),
(27, 1.00, '{"band":"Low","factors":[{"max":2,"label":"OTP requests in 15 minutes","points":1,"reason":"Several OTP requests in a short window."},{"max":3,"label":"Questionnaire speed","points":0,"reason":"Normal completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 42"}'::jsonb, 'pending', 2026-11-14 01:49:58.083097),
(28, 3.00, '{"band":"Medium","factors":[{"max":2,"label":"Failed OTP attempts","points":0,"reason":"No failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":0,"reason":"Normal completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":3,"reason":"Video matches another applicant."}],"auto_high":["Video matches another applicant."]}'::jsonb, '{"summary":"Risk evaluation for applicant 45"}'::jsonb, 'pending', 2026-11-14 01:49:58.083875),
(29, 3.00, '{"band":"Medium","factors":[{"max":2,"label":"Failed OTP attempts","points":0,"reason":"No failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":0,"reason":"Normal completion time."},{"max":3,"label":"Same device or IP as other applicants","points":0,"reason":"No device overlap detected."},{"max":3,"label":"Video matches another applicant","points":3,"reason":"Video matches another applicant."}],"auto_high":["Video matches another applicant."]}'::jsonb, '{"summary":"Risk evaluation for applicant 47"}'::jsonb, 'pending', 2026-11-14 01:49:58.084681),
(30, 3.00, '{"band":"Medium","factors":[{"max":2,"label":"Failed OTP attempts","points":0,"reason":"No failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":1,"reason":"Slightly fast completion time."},{"max":3,"label":"Same device or IP as other applicants","points":1,"reason":"Possible device overlap."},{"max":3,"label":"Video matches another applicant","points":1,"reason":"Possible video similarity."}],"auto_high":[]}'::jsonb, '{"summary":"Risk evaluation for applicant 48"}'::jsonb, 'pending', 2026-11-14 01:49:58.085411),
(31, 5.00, '{"band":"Medium","factors":[{"max":2,"label":"Failed OTP attempts","points":2,"reason":"Multiple failed OTP attempts."},{"max":3,"label":"Questionnaire speed","points":1,"reason":"Slightly fast completion time."},{"max":3,"label":"Same device or IP as other applicants","points":2,"reason":"Same device as 1 other applicant."},{"max":3,"label":"Video matches another applicant","points":0,"reason":"No video match found."}],"auto_high":["Multiple failed OTP attempts."]}'::jsonb, '{"summary":"Risk evaluation for applicant 49"}'::jsonb, 'pending', 2026-11-14 01:49:58.087056);
