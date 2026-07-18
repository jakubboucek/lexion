-- Human-readable URL slugs for courts (SPZ-style abbreviations).
-- Prefix by court type (ns/nss/vs-/ks-/ms-/os-) disambiguates cities that
-- host both a regional and a district court (ks-cb vs os-cb).
ALTER TABLE `court` ADD COLUMN `slug` VARCHAR(30) NULL DEFAULT NULL AFTER `kod`;

UPDATE `court` SET `slug` = 'ns' WHERE `kod` = 'NS';
UPDATE `court` SET `slug` = 'nss' WHERE `kod` = 'NSS';
UPDATE `court` SET `slug` = 'vs-pha' WHERE `kod` = 'VSPHAAB';
UPDATE `court` SET `slug` = 'vs-olc' WHERE `kod` = 'VSSEMOL';
UPDATE `court` SET `slug` = 'ks-pha' WHERE `kod` = 'KSSTCAB';
UPDATE `court` SET `slug` = 'ms-pha' WHERE `kod` = 'MSPHAAB';
UPDATE `court` SET `slug` = 'ks-cb' WHERE `kod` = 'KSJICCB';
UPDATE `court` SET `slug` = 'ks-hk' WHERE `kod` = 'KSVYCHK';
UPDATE `court` SET `slug` = 'ks-ova' WHERE `kod` = 'KSSEMOS';
UPDATE `court` SET `slug` = 'ks-plz' WHERE `kod` = 'KSZPCPM';
UPDATE `court` SET `slug` = 'ks-ul' WHERE `kod` = 'KSSCEUL';
UPDATE `court` SET `slug` = 'ks-bo' WHERE `kod` = 'KSJIMBM';
UPDATE `court` SET `slug` = 'ms-bo' WHERE `kod` = 'OSJIMBM';
UPDATE `court` SET `slug` = 'os-pha-1' WHERE `kod` = 'OSPHA01';
UPDATE `court` SET `slug` = 'os-pha-2' WHERE `kod` = 'OSPHA02';
UPDATE `court` SET `slug` = 'os-pha-3' WHERE `kod` = 'OSPHA03';
UPDATE `court` SET `slug` = 'os-pha-4' WHERE `kod` = 'OSPHA04';
UPDATE `court` SET `slug` = 'os-pha-5' WHERE `kod` = 'OSPHA05';
UPDATE `court` SET `slug` = 'os-pha-6' WHERE `kod` = 'OSPHA06';
UPDATE `court` SET `slug` = 'os-pha-7' WHERE `kod` = 'OSPHA07';
UPDATE `court` SET `slug` = 'os-pha-8' WHERE `kod` = 'OSPHA08';
UPDATE `court` SET `slug` = 'os-pha-9' WHERE `kod` = 'OSPHA09';
UPDATE `court` SET `slug` = 'os-pha-10' WHERE `kod` = 'OSPHA10';
UPDATE `court` SET `slug` = 'os-pha-vychod' WHERE `kod` = 'OSSTCPY';
UPDATE `court` SET `slug` = 'os-pha-zapad' WHERE `kod` = 'OSSTCPZ';
UPDATE `court` SET `slug` = 'os-bn' WHERE `kod` = 'OSSTCBN';
UPDATE `court` SET `slug` = 'os-be' WHERE `kod` = 'OSSTCBE';
UPDATE `court` SET `slug` = 'os-bk' WHERE `kod` = 'OSJIMBK';
UPDATE `court` SET `slug` = 'os-bo' WHERE `kod` = 'OSJIMBO';
UPDATE `court` SET `slug` = 'os-br' WHERE `kod` = 'OSSEMBR';
UPDATE `court` SET `slug` = 'os-bv' WHERE `kod` = 'OSJIMBV';
UPDATE `court` SET `slug` = 'os-cl' WHERE `kod` = 'OSSCECL';
UPDATE `court` SET `slug` = 'os-cb' WHERE `kod` = 'OSJICCB';
UPDATE `court` SET `slug` = 'os-ck' WHERE `kod` = 'OSJICCK';
UPDATE `court` SET `slug` = 'os-ch' WHERE `kod` = 'OSZPCCH';
UPDATE `court` SET `slug` = 'os-cv' WHERE `kod` = 'OSSCECV';
UPDATE `court` SET `slug` = 'os-cr' WHERE `kod` = 'OSVYCCR';
UPDATE `court` SET `slug` = 'os-dc' WHERE `kod` = 'OSSCEDC';
UPDATE `court` SET `slug` = 'os-do' WHERE `kod` = 'OSZPCDO';
UPDATE `court` SET `slug` = 'os-fm' WHERE `kod` = 'OSSEMFM';
UPDATE `court` SET `slug` = 'os-hb' WHERE `kod` = 'OSVYCHB';
UPDATE `court` SET `slug` = 'os-ho' WHERE `kod` = 'OSJIMHO';
UPDATE `court` SET `slug` = 'os-hk' WHERE `kod` = 'OSVYCHK';
UPDATE `court` SET `slug` = 'os-jn' WHERE `kod` = 'OSSCEJN';
UPDATE `court` SET `slug` = 'os-je' WHERE `kod` = 'OSSEMJE';
UPDATE `court` SET `slug` = 'os-jc' WHERE `kod` = 'OSVYCJC';
UPDATE `court` SET `slug` = 'os-ji' WHERE `kod` = 'OSJIMJI';
UPDATE `court` SET `slug` = 'os-jh' WHERE `kod` = 'OSJICJH';
UPDATE `court` SET `slug` = 'os-kv' WHERE `kod` = 'OSZPCKV';
UPDATE `court` SET `slug` = 'os-ki' WHERE `kod` = 'OSSEMKA';
UPDATE `court` SET `slug` = 'os-kl' WHERE `kod` = 'OSSTCKL';
UPDATE `court` SET `slug` = 'os-kt' WHERE `kod` = 'OSZPCKT';
UPDATE `court` SET `slug` = 'os-ko' WHERE `kod` = 'OSSTCKO';
UPDATE `court` SET `slug` = 'os-km' WHERE `kod` = 'OSJIMKM';
UPDATE `court` SET `slug` = 'os-kh' WHERE `kod` = 'OSSTCKH';
UPDATE `court` SET `slug` = 'os-lbc' WHERE `kod` = 'OSSCELB';
UPDATE `court` SET `slug` = 'os-lt' WHERE `kod` = 'OSSCELT';
UPDATE `court` SET `slug` = 'os-ln' WHERE `kod` = 'OSSCELN';
UPDATE `court` SET `slug` = 'os-me' WHERE `kod` = 'OSSTCME';
UPDATE `court` SET `slug` = 'os-mb' WHERE `kod` = 'OSSTCMB';
UPDATE `court` SET `slug` = 'os-mo' WHERE `kod` = 'OSSCEMO';
UPDATE `court` SET `slug` = 'os-na' WHERE `kod` = 'OSVYCNA';
UPDATE `court` SET `slug` = 'os-nj' WHERE `kod` = 'OSSEMNJ';
UPDATE `court` SET `slug` = 'os-nb' WHERE `kod` = 'OSSTCNB';
UPDATE `court` SET `slug` = 'os-olc' WHERE `kod` = 'OSSEMOC';
UPDATE `court` SET `slug` = 'os-op' WHERE `kod` = 'OSSEMOP';
UPDATE `court` SET `slug` = 'os-ova' WHERE `kod` = 'OSSEMOS';
UPDATE `court` SET `slug` = 'os-pce' WHERE `kod` = 'OSVYCPA';
UPDATE `court` SET `slug` = 'os-pe' WHERE `kod` = 'OSJICPE';
UPDATE `court` SET `slug` = 'os-pi' WHERE `kod` = 'OSJICPI';
UPDATE `court` SET `slug` = 'os-plz-jih' WHERE `kod` = 'OSZPCPJ';
UPDATE `court` SET `slug` = 'os-plz' WHERE `kod` = 'OSZPCPM';
UPDATE `court` SET `slug` = 'os-plz-sever' WHERE `kod` = 'OSZPCPS';
UPDATE `court` SET `slug` = 'os-pt' WHERE `kod` = 'OSJICPT';
UPDATE `court` SET `slug` = 'os-pr' WHERE `kod` = 'OSSEMPR';
UPDATE `court` SET `slug` = 'os-pb' WHERE `kod` = 'OSSTCPB';
UPDATE `court` SET `slug` = 'os-pv' WHERE `kod` = 'OSJIMPV';
UPDATE `court` SET `slug` = 'os-ra' WHERE `kod` = 'OSSTCRA';
UPDATE `court` SET `slug` = 'os-ro' WHERE `kod` = 'OSZPCRO';
UPDATE `court` SET `slug` = 'os-rk' WHERE `kod` = 'OSVYCRK';
UPDATE `court` SET `slug` = 'os-sm' WHERE `kod` = 'OSVYCSM';
UPDATE `court` SET `slug` = 'os-so' WHERE `kod` = 'OSZPCSO';
UPDATE `court` SET `slug` = 'os-st' WHERE `kod` = 'OSJICST';
UPDATE `court` SET `slug` = 'os-su' WHERE `kod` = 'OSSEMSU';
UPDATE `court` SET `slug` = 'os-sy' WHERE `kod` = 'OSVYCSY';
UPDATE `court` SET `slug` = 'os-ta' WHERE `kod` = 'OSJICTA';
UPDATE `court` SET `slug` = 'os-tc' WHERE `kod` = 'OSZPCTC';
UPDATE `court` SET `slug` = 'os-tp' WHERE `kod` = 'OSSCETP';
UPDATE `court` SET `slug` = 'os-tu' WHERE `kod` = 'OSVYCTU';
UPDATE `court` SET `slug` = 'os-tr' WHERE `kod` = 'OSJIMTR';
UPDATE `court` SET `slug` = 'os-uh' WHERE `kod` = 'OSJIMUH';
UPDATE `court` SET `slug` = 'os-ul' WHERE `kod` = 'OSSCEUL';
UPDATE `court` SET `slug` = 'os-uo' WHERE `kod` = 'OSVYCUO';
UPDATE `court` SET `slug` = 'os-vs' WHERE `kod` = 'OSSEMVS';
UPDATE `court` SET `slug` = 'os-vy' WHERE `kod` = 'OSJIMVY';
UPDATE `court` SET `slug` = 'os-zl' WHERE `kod` = 'OSJIMZL';
UPDATE `court` SET `slug` = 'os-zn' WHERE `kod` = 'OSJIMZN';
UPDATE `court` SET `slug` = 'os-zr' WHERE `kod` = 'OSJIMZR';

ALTER TABLE `court`
    MODIFY COLUMN `slug` VARCHAR(30) NOT NULL,
    ADD UNIQUE KEY `uq_court_slug` (`slug`);

-- Revision: derive the city part from infoSoud's own court code instead of
-- SPZ-style abbreviations. The `kod` column encodes it as its last two chars
-- (OSSEMOP = OS + SEM region + OP city); we drop the region and keep the city.
-- Exceptions: Prague courts use PH (infoSoud codes Prague as AB), and Prague
-- district courts keep the zero-padded district number as a third segment
-- (OSPHA03 -> os-ph-03). Verified collision-free across all 98 courts.
UPDATE `court` SET `slug` = 'vs-ph' WHERE `kod` = 'VSPHAAB';
UPDATE `court` SET `slug` = 'vs-ol' WHERE `kod` = 'VSSEMOL';
UPDATE `court` SET `slug` = 'ks-ph' WHERE `kod` = 'KSSTCAB';
UPDATE `court` SET `slug` = 'ms-ph' WHERE `kod` = 'MSPHAAB';
UPDATE `court` SET `slug` = 'ks-os' WHERE `kod` = 'KSSEMOS';
UPDATE `court` SET `slug` = 'ks-pm' WHERE `kod` = 'KSZPCPM';
UPDATE `court` SET `slug` = 'ks-bm' WHERE `kod` = 'KSJIMBM';
UPDATE `court` SET `slug` = 'ms-bm' WHERE `kod` = 'OSJIMBM';
UPDATE `court` SET `slug` = 'os-ph-01' WHERE `kod` = 'OSPHA01';
UPDATE `court` SET `slug` = 'os-ph-02' WHERE `kod` = 'OSPHA02';
UPDATE `court` SET `slug` = 'os-ph-03' WHERE `kod` = 'OSPHA03';
UPDATE `court` SET `slug` = 'os-ph-04' WHERE `kod` = 'OSPHA04';
UPDATE `court` SET `slug` = 'os-ph-05' WHERE `kod` = 'OSPHA05';
UPDATE `court` SET `slug` = 'os-ph-06' WHERE `kod` = 'OSPHA06';
UPDATE `court` SET `slug` = 'os-ph-07' WHERE `kod` = 'OSPHA07';
UPDATE `court` SET `slug` = 'os-ph-08' WHERE `kod` = 'OSPHA08';
UPDATE `court` SET `slug` = 'os-ph-09' WHERE `kod` = 'OSPHA09';
UPDATE `court` SET `slug` = 'os-ph-10' WHERE `kod` = 'OSPHA10';
UPDATE `court` SET `slug` = 'os-py' WHERE `kod` = 'OSSTCPY';
UPDATE `court` SET `slug` = 'os-pz' WHERE `kod` = 'OSSTCPZ';
UPDATE `court` SET `slug` = 'os-pm' WHERE `kod` = 'OSZPCPM';
UPDATE `court` SET `slug` = 'os-pj' WHERE `kod` = 'OSZPCPJ';
UPDATE `court` SET `slug` = 'os-ps' WHERE `kod` = 'OSZPCPS';
UPDATE `court` SET `slug` = 'os-os' WHERE `kod` = 'OSSEMOS';
UPDATE `court` SET `slug` = 'os-oc' WHERE `kod` = 'OSSEMOC';
UPDATE `court` SET `slug` = 'os-lb' WHERE `kod` = 'OSSCELB';
UPDATE `court` SET `slug` = 'os-pa' WHERE `kod` = 'OSVYCPA';
UPDATE `court` SET `slug` = 'os-ka' WHERE `kod` = 'OSSEMKA';
