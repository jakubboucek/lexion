-- Judicial region (soudni kraj per the 1960 territorial division, still used
-- by the justice system). infoSoud encodes it as the middle three characters
-- of the court code (OSSEMOP = OS + SEM region + OP city). Values: PHA, STC,
-- JIC, ZPC, SCE, VYC, JIM, SEM; NULL for nationwide courts (NS, NSS).
ALTER TABLE `court`
    ADD COLUMN `region` CHAR(3) NULL DEFAULT NULL AFTER `level`,
    ADD KEY `idx_court_region` (`region`);

UPDATE `court` SET `region` = SUBSTRING(`kod`, 3, 3) WHERE LENGTH(`kod`) = 7;
