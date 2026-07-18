-- Infosoud internally labels the Supreme Court organization "NSJIMBM"
-- (seen in udalosti[].znackaId.organizace), while our court codelist uses
-- the synthetic "NS" code. Map it via the court_prefix codelist so case
-- references resolve to the right court.
INSERT INTO `court_prefix` (`prefix`, `court_kod`, `note`)
VALUES ('NSJIMBM', 'NS', 'infosoud org kód Nejvyššího soudu');
