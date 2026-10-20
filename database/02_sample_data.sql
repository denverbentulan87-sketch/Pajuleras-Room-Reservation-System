-- =====================================================================
--  OPTIONAL demo data for Pajuleras Boarding House
--  Import AFTER 01_schema.sql, on an empty database, ONCE.
--
--  Everything here is made-up sample data (names, rooms, rates) so the
--  system has something to show. Dates are relative to the day you
--  import it. Delete or edit it from the Admin panel, or skip this file
--  and add the real rooms yourself.
-- =====================================================================
USE `pajuleras_bh`;
SET NAMES utf8mb4;

INSERT INTO `rooms` (`id`,`room_no`,`room_name`,`room_type`,`gender_policy`,`capacity`,`monthly_rate`,`deposit_amount`,`amenities`,`description`,`status`,`is_public`) VALUES
(1,'1','Near the front door','shared','female',4,600.00,300.00,'Bunk beds, wall outlet, shared comfort room','Bright shared room with two bunk beds for female students and workers.','active',1),
(2,'2','Beside the garden','shared','female',4,600.00,300.00,'Bunk beds, wall outlet, shared comfort room','Quiet shared room next to the plants, with two bunk beds.','active',1),
(3,'3','Back room','shared','male',6,550.00,300.00,'Bunk beds, wall outlet, shared comfort room','Our largest room with three bunk beds for male students and workers.','active',1),
(4,'4',NULL,'shared','male',4,600.00,300.00,'Bunk beds, wall outlet, shared comfort room','Shared room with two bunk beds for male tenants.','active',1),
(5,'5','Private room','private','any',1,1500.00,500.00,'Single bed, wall outlet, own door lock','A private room for one person who wants more quiet.','active',1),
(6,'6','Being repaired','shared','any',2,600.00,300.00,'Bunk bed','Closed while the roof is being repaired.','maintenance',1);

INSERT INTO `tenants` (`id`,`tenant_code`,`full_name`,`gender`,`birth_date`,`contact_no`,`email`,`home_address`,`occupation`,`school_or_workplace`,`id_presented`,`guardian_name`,`guardian_contact`,`status`) VALUES
(1,'T-0001','Maria Fe Lumantas','female','2005-03-14','09171230001',NULL,'Brgy. Ilaud, Inabanga, Bohol','student','Inabanga College of Arts and Sciences','School ID','Lorna Lumantas','09171239001','active'),
(2,'T-0002','Jennifer Boiser','female','2004-09-02','09171230002',NULL,'Brgy. Cawayan, Buenavista, Bohol','student','Inabanga College of Arts and Sciences','School ID','Ernesto Boiser','09171239002','active'),
(3,'T-0003','Ryan Cabahug','male','2003-11-21','09171230003',NULL,'Brgy. Tambook, Clarin, Bohol','student','Inabanga College of Arts and Sciences','School ID','Nelia Cabahug','09171239003','active'),
(4,'T-0004','Mark Anthony Uy','male','1999-06-30','09171230004',NULL,'Brgy. Poblacion, Sagbayan, Bohol','employee','Inabanga Rural Health Unit',"Driver's license",'Teresita Uy','09171239004','active'),
(5,'T-0005','Angelica Torregosa','female','1996-01-18','09171230005','angelica.t@example.com','Brgy. Lawis, Catigbian, Bohol','employee','Public school teacher','PRC ID','Ramon Torregosa','09171239005','active'),
(6,'T-0006','Shiela Mae Ramos','female','2005-07-09','09171230006',NULL,'Brgy. Baogo, Inabanga, Bohol','student','Inabanga College of Arts and Sciences','School ID','Alma Ramos','09171239006','active'),
(7,'T-0007','Kenneth Digal','male','2004-12-01','09171230007',NULL,'Brgy. Fatima, Inabanga, Bohol','student','Inabanga College of Arts and Sciences','School ID','Melchor Digal','09171239007','active'),
(8,'T-0008','Roselyn Gudmalin','female','2003-04-25','09171230008',NULL,'Brgy. Nabuad, Inabanga, Bohol','student','Inabanga College of Arts and Sciences','School ID','Josefa Gudmalin','09171239008','active'),
(9,'T-0009','Jomar Pepito','male','2004-02-11','09171230009',NULL,'Brgy. Bugang, Inabanga, Bohol','student','Inabanga College of Arts and Sciences','School ID',NULL,NULL,'active');

-- Inquiries (some already turned into reservations)
INSERT INTO `inquiries` (`id`,`ref_code`,`name`,`contact_no`,`email`,`room_id`,`preferred_move_in`,`occupation`,`subject`,`status`,`admin_unread`,`ip`,`created_at`,`updated_at`) VALUES
(1,'K7M2-9QXA','Karen Mae Bongcayao','09171230010',NULL,2,DATE_ADD(CURDATE(), INTERVAL 21 DAY),'student','Inquiry about Room 2','new',1,'127.0.0.1',DATE_SUB(NOW(), INTERVAL 3 HOUR),DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(2,'H4TE-8WPB','Dennis Salazar','09171230011','dennis.s@example.com',3,DATE_ADD(CURDATE(), INTERVAL 30 DAY),'employee','Inquiry about Room 3','replied',0,'127.0.0.1',DATE_SUB(NOW(), INTERVAL 2 DAY),DATE_SUB(NOW(), INTERVAL 1 DAY)),
(3,'R3ZN-6DCU','Shiela Mae Ramos','09171230006',NULL,2,DATE_ADD(CURDATE(), INTERVAL 6 DAY),'student','Inquiry about Room 2','converted',0,'127.0.0.1',DATE_SUB(NOW(), INTERVAL 9 DAY),DATE_SUB(NOW(), INTERVAL 8 DAY));

INSERT INTO `inquiry_messages` (`inquiry_id`,`sender`,`body`,`created_at`) VALUES
(1,'customer','Good day po. Is there still a bed in Room 2 for June? How much is the monthly rent and what do I need to bring?',DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(2,'customer','Hello, I will start working in Inabanga next month. Do you have an open bed in Room 3? Is there a deposit?',DATE_SUB(NOW(), INTERVAL 2 DAY)),
(2,'admin','Good day Sir Dennis. Yes, Room 3 still has open beds. Rent is 550 a month plus a 300 refundable deposit. Please bring a valid ID and the contact number of a family member.',DATE_SUB(NOW(), INTERVAL 1 DAY)),
(3,'customer','Hi po, I am enrolling at ICAS this semester. Can I reserve a bed in Room 2 starting next week?',DATE_SUB(NOW(), INTERVAL 9 DAY)),
(3,'admin','Yes, there is a bed. Please come by so we can record your details. The advance is one month of rent.',DATE_SUB(NOW(), INTERVAL 8 DAY));

-- Reservations
INSERT INTO `reservations` (`id`,`tenant_id`,`room_id`,`inquiry_id`,`beds`,`move_in_date`,`move_out_date`,`status`,`monthly_rate`,`deposit_amount`,`advance_amount`,`confirmed_at`,`checked_in_at`,`checked_out_at`,`cancelled_at`,`cancel_reason`,`notes`,`created_at`) VALUES
(1,1,1,NULL,1,DATE_SUB(CURDATE(), INTERVAL 75 DAY),NULL,'checked_in',600.00,300.00,600.00,DATE_SUB(NOW(), INTERVAL 80 DAY),CONCAT(DATE_SUB(CURDATE(), INTERVAL 75 DAY),' 09:30:00'),NULL,NULL,NULL,NULL,DATE_SUB(NOW(), INTERVAL 82 DAY)),
(2,2,1,NULL,1,DATE_SUB(CURDATE(), INTERVAL 40 DAY),NULL,'checked_in',600.00,300.00,600.00,DATE_SUB(NOW(), INTERVAL 45 DAY),CONCAT(DATE_SUB(CURDATE(), INTERVAL 40 DAY),' 10:15:00'),NULL,NULL,NULL,NULL,DATE_SUB(NOW(), INTERVAL 47 DAY)),
(3,3,3,NULL,1,DATE_SUB(CURDATE(), INTERVAL 20 DAY),NULL,'checked_in',550.00,300.00,550.00,DATE_SUB(NOW(), INTERVAL 25 DAY),CONCAT(DATE_SUB(CURDATE(), INTERVAL 20 DAY),' 14:00:00'),NULL,NULL,NULL,NULL,DATE_SUB(NOW(), INTERVAL 27 DAY)),
(4,4,3,NULL,1,DATE_SUB(CURDATE(), INTERVAL 100 DAY),NULL,'checked_in',550.00,300.00,550.00,DATE_SUB(NOW(), INTERVAL 105 DAY),CONCAT(DATE_SUB(CURDATE(), INTERVAL 100 DAY),' 08:45:00'),NULL,NULL,NULL,'Works night shifts; quiet hours requested.',DATE_SUB(NOW(), INTERVAL 106 DAY)),
(5,5,5,NULL,1,DATE_SUB(CURDATE(), INTERVAL 10 DAY),NULL,'checked_in',1500.00,500.00,1500.00,DATE_SUB(NOW(), INTERVAL 15 DAY),CONCAT(DATE_SUB(CURDATE(), INTERVAL 10 DAY),' 16:20:00'),NULL,NULL,NULL,NULL,DATE_SUB(NOW(), INTERVAL 16 DAY)),
(6,6,2,3,1,DATE_ADD(CURDATE(), INTERVAL 6 DAY),NULL,'confirmed',600.00,300.00,600.00,DATE_SUB(NOW(), INTERVAL 7 DAY),NULL,NULL,NULL,NULL,NULL,DATE_SUB(NOW(), INTERVAL 8 DAY)),
(7,7,4,NULL,1,DATE_ADD(CURDATE(), INTERVAL 20 DAY),NULL,'pending',600.00,300.00,600.00,NULL,NULL,NULL,NULL,NULL,'Will confirm after his parents visit.',DATE_SUB(NOW(), INTERVAL 1 DAY)),
(8,8,2,NULL,1,DATE_SUB(CURDATE(), INTERVAL 200 DAY),DATE_SUB(CURDATE(), INTERVAL 30 DAY),'completed',600.00,300.00,600.00,DATE_SUB(NOW(), INTERVAL 205 DAY),CONCAT(DATE_SUB(CURDATE(), INTERVAL 200 DAY),' 09:00:00'),CONCAT(DATE_SUB(CURDATE(), INTERVAL 30 DAY),' 11:00:00'),NULL,NULL,NULL,DATE_SUB(NOW(), INTERVAL 207 DAY)),
(9,9,4,NULL,1,DATE_SUB(CURDATE(), INTERVAL 15 DAY),NULL,'cancelled',600.00,300.00,600.00,NULL,NULL,NULL,DATE_SUB(NOW(), INTERVAL 12 DAY),'Found a place closer to his home',NULL,DATE_SUB(NOW(), INTERVAL 18 DAY));
UPDATE `reservations` SET `reservation_no` = CONCAT('R', YEAR(CURDATE()), '-', LPAD(`id`, 4, '0'));

-- Payments (advance + deposit at move-in, then rent each month)
INSERT INTO `payments` (`id`,`reservation_id`,`tenant_id`,`payment_type`,`amount`,`payment_date`,`method`,`reference_no`,`remarks`) VALUES
(1,1,1,'advance',600.00,DATE_SUB(CURDATE(), INTERVAL 80 DAY),'cash',NULL,'Advance to secure the bed'),
(2,1,1,'deposit',300.00,DATE_SUB(CURDATE(), INTERVAL 75 DAY),'cash',NULL,NULL),
(3,1,1,'rent',600.00,DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 75 DAY), INTERVAL 1 MONTH),'gcash','GC-58201934','Second month'),
(4,1,1,'rent',600.00,DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 75 DAY), INTERVAL 2 MONTH),'cash',NULL,'Third month'),
(5,2,2,'advance',600.00,DATE_SUB(CURDATE(), INTERVAL 45 DAY),'cash',NULL,'Advance to secure the bed'),
(6,2,2,'deposit',300.00,DATE_SUB(CURDATE(), INTERVAL 40 DAY),'cash',NULL,NULL),
(7,3,3,'advance',550.00,DATE_SUB(CURDATE(), INTERVAL 25 DAY),'gcash','GC-58733120','Advance to secure the bed'),
(8,3,3,'deposit',300.00,DATE_SUB(CURDATE(), INTERVAL 20 DAY),'cash',NULL,NULL),
(9,4,4,'advance',550.00,DATE_SUB(CURDATE(), INTERVAL 105 DAY),'cash',NULL,NULL),
(10,4,4,'deposit',300.00,DATE_SUB(CURDATE(), INTERVAL 100 DAY),'cash',NULL,NULL),
(11,4,4,'rent',550.00,DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 100 DAY), INTERVAL 1 MONTH),'cash',NULL,'Second month'),
(12,4,4,'rent',550.00,DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 100 DAY), INTERVAL 2 MONTH),'cash',NULL,'Third month'),
(13,5,5,'advance',1500.00,DATE_SUB(CURDATE(), INTERVAL 15 DAY),'bank_transfer','BT-902114',NULL),
(14,5,5,'deposit',500.00,DATE_SUB(CURDATE(), INTERVAL 10 DAY),'cash',NULL,NULL),
(15,6,6,'advance',600.00,DATE_SUB(CURDATE(), INTERVAL 7 DAY),'gcash','GC-59012877','Advance to secure the bed'),
(16,8,8,'advance',600.00,DATE_SUB(CURDATE(), INTERVAL 205 DAY),'cash',NULL,NULL),
(17,8,8,'deposit',300.00,DATE_SUB(CURDATE(), INTERVAL 200 DAY),'cash',NULL,NULL),
(18,8,8,'rent',600.00,DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 200 DAY), INTERVAL 1 MONTH),'cash',NULL,NULL),
(19,8,8,'rent',600.00,DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 200 DAY), INTERVAL 2 MONTH),'cash',NULL,NULL),
(20,8,8,'rent',600.00,DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 200 DAY), INTERVAL 3 MONTH),'cash',NULL,NULL),
(21,8,8,'rent',600.00,DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 200 DAY), INTERVAL 4 MONTH),'cash',NULL,NULL),
(22,8,8,'rent',600.00,DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 200 DAY), INTERVAL 5 MONTH),'cash',NULL,NULL),
(23,8,8,'refund',300.00,DATE_SUB(CURDATE(), INTERVAL 30 DAY),'cash',NULL,'Deposit returned at check-out');
UPDATE `payments` SET `receipt_no` = CONCAT('OR-', LPAD(`id`, 6, '0'));

INSERT INTO `activity_log` (`action`,`entity`,`entity_id`,`details`,`ip`) VALUES
('reservation_created','reservation',6,'Sample reservation recorded','127.0.0.1');
