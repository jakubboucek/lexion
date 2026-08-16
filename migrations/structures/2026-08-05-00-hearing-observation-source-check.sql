-- The source column has always been documented as 'infojednani' | 'infosoud'
-- but nothing enforced it. The typed-entity refactoring maps it to a PHP enum
-- (App\Model\Hearing\ObservationSource), and the project's rule is that an
-- enum is only justified when the database holds the value set - so pin it.
--
-- Verify before: SELECT DISTINCT source FROM hearing_observation; -- infojednani
ALTER TABLE `hearing_observation`
    ADD CONSTRAINT `chk_hearing_observation_source` CHECK (`source` IN ('infojednani', 'infosoud'));
