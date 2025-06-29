--
-- Table structure for table `contactmanager_tracking_sendgrid`
--

CREATE TABLE IF NOT EXISTS /*_*/contactmanager_tracking_sendgrid (
	`id` int(11) NOT NULL,
	`page_id` int(11) NOT NULL,
	`email` varchar(255) NOT NULL,
	`event_type` varchar(50) NOT NULL,
	`timestamp` int(11) NOT NULL,
	`smtp_id` varchar(255) DEFAULT NULL,
	`sg_event_id` varchar(255) DEFAULT NULL,
	`sg_message_id` varchar(255) DEFAULT NULL,
	`category` varchar(255) DEFAULT NULL,
	`ip` varchar(45) DEFAULT NULL,
	`url` text DEFAULT NULL,
	`useragent` text DEFAULT NULL,
	`response` text DEFAULT NULL,
	`reason` text DEFAULT NULL,
	`sg_machine_open` tinyint(1) DEFAULT NULL,
	`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Indexes for table `sendgrid_events`
--
ALTER TABLE /*_*/contactmanager_tracking_sendgrid
	ADD PRIMARY KEY (`id`);

ALTER TABLE /*_*/contactmanager_tracking_sendgrid
	ADD INDEX `page_id` (`page_id`);

ALTER TABLE /*_*/contactmanager_tracking_sendgrid
	ADD INDEX `email` (`email`);

ALTER TABLE /*_*/contactmanager_tracking_sendgrid
	ADD INDEX `event_type` (`event_type`);

ALTER TABLE /*_*/contactmanager_tracking_sendgrid
	ADD INDEX `sg_message_id` (`sg_message_id`);

--
-- AUTO_INCREMENT for table `contactmanager_tracking_sendgrid`
--
ALTER TABLE /*_*/contactmanager_tracking_sendgrid
	MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

