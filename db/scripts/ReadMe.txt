J/A+A/670/A19       Fifth Catalogue of Nearby Stars (CNS5)      (Golovin+, 2023)
================================================================================
The Fifth Catalogue of Nearby Stars (CNS5).
    Golovin A., Reffert S., Just A., Jordan S., Vani A., Jahreiss H.
   <Astron. Astrophys., 670, A19 (2023)>
   =2023A&A...670A..19G    (SIMBAD/NED BibCode)
================================================================================
ADC_Keywords: Stars, nearby ; Photometry ; Optical ; Photometry, infrared ;
              Parallaxes, trigonometric ; Proper motions ; Radial velocities
Keywords: catalogs - stars: distances - Hertzsprung-Russell and C-M diagrams -
          stars: luminosity function, mass function - solar neighborhood -
          Galaxy: stellar content

Abstract:
    We present the compilation of the Fifth Catalogue of Nearby Stars
    (CNS5), based on astrometric and photometric data from Gaia EDR3 and
    HIPPARCOS and supplemented with parallaxes from ground-based
    astrometric surveys carried out in the infrared.

    The aim of the CNS5 is to provide the most complete sample of objects
    in the solar neighbourhood. For all known stars and brown dwarfs in
    the 25 pc sphere around the Sun, basic astrometric and photometric
    parameters are given. Furthermore, we provide the colour-magnitude
    diagram (CMD) and various luminosity functions of the stellar content
    in the solar neighbourhood, and characterise the completeness of the
    CNS5 catalogue.

    We compiled a sample of stars and brown dwarfs that are most likely
    located within 25 pc of the Sun, taking space-based parallaxes from
    Gaia EDR3 and HIPPARCOS as well as ground-based parallaxes from Best
    et al. (2021AJ....161...42B, J/AJ/161/42), Kirkpatrick et al.
    (2021ApJS..253....7K, Cat. J/ApJS/253/7), and from the CNS4 into
    account. We developed a set of selection criteria to clean the sample
    from spurious sources. Furthermore, we show that effects of blending
    in the Gaia photometry, which mainly affect the faint and red sources
    in Gaia, can be mitigated to reliably place those objects in a CMD. We
    also assessed the completeness of the CNS5 using a Kolmogorov-Smirnov
    test and derive observational optical and mid-infrared (MIR)
    luminosity functions for the main-sequence stars and white dwarfs
    (WDs) in the solar neighbourhood.

    The CNS5 contains 5931 objects, including 5230 stars (4946
    main-sequence stars, 20 red giants and 264 white dwarfs) and 701 brown
    dwarfs. We find that the CNS5 catalogue is statistically complete down
    to 19.7 mag in the G-band and 11.8 mag in W1-band absolute magnitudes,
    corresponding to a spectral type of L8. The stellar number density in
    the solar neighbourhood is (7.99+/-0.11)x10^-2^stars/pc^3^, and about
    72% of stars in the solar neighbourhood are M dwarfs. Furthermore, we
    show that the WD sample in CNS5 is statistically complete within 25
    pc. The derived number density of WDs is
    (4.03+/-0.25)x10^-3^stars/pc^3^. The ratio between stars and brown
    dwarfs within 15pc is 4.6+/-0.4, whereas within 25 pc it is 7.5+/-0.3.
    Thus, we estimate that about one third of brown dwarfs are still
    missing within 25pc, particularly those with spectral types later than
    L8 and distances close to the 25pc limit.

Description:
    The Fifth Catalogue of Nearby Stars (CNS5) aims to provide the most
    volume-complete sample of stars in the solar neighbourhood. The CNS5
    is compiled based on trigonometric parallaxes from Gaia EDR3 and
    Hipparcos, and supplemented with astrometric data from Spitzer and
    ground-based surveys carried out in the infrared. The CNS5 catalogue
    is statistically complete down to 19.7mag in G-band and 11.8mag in
    W1-band absolute magnitudes, corresponding to a spectral type of L8.
    Continuous updates of observational data for nearby stars from all
    sources were collected and evaluated. For all known stars in the 25 pc
    sphere around the Sun, the best values of positions in space,
    velocities, and magnitudes in different filters are presented.

File Summary:
--------------------------------------------------------------------------------
 FileName      Lrecl  Records   Explanations
--------------------------------------------------------------------------------
ReadMe            80        .   This file
cns5.dat         761     5909   Fifth Catalogue of Nearby Stars (CNS5)
                                 (corrected version 13-Dec-2023)
--------------------------------------------------------------------------------

See also:
        I/239 : The Hipparcos and Tycho Catalogues (ESA 1997)
        I/355 : Gaia DR3 Part 1. Main source (Gaia Collaboration, 2022)
 J/AJ/161/42  : Cool dwarfs volume-limited sample. I. L0-T8 dwarfs (Best+, 2021)
 J/ApJS/253/7 : Brown dwarfs within 20pc full-sky census (Kirkpatrick+, 2021)

 https://dc.zah.uni-heidelberg.de/cns5/q/cone/form : CNS5 Home Page

Byte-by-byte Description of file: cns5.dat
--------------------------------------------------------------------------------
   Bytes Format Units     Label   Explanations
--------------------------------------------------------------------------------
   1-  4  I4    ---       CNS5    [0/5930] CNS5 designation (cns5_id)
   6- 11  A6    ---       GJ      Gliese-Jahreiss number (gj_id)
  13- 16  A4    ---       Comp    Suffix for a component of binary or
                                   multiple system (component_id)
      18  I1    ---       NComp   [2/6]?=- Total number of components in the
                                    system (n_components)
      20  I1    ---       P?      [0/1] True for the primary of a multiple
                                   system (primary_flag)
  22- 26  A5    ---       GJp     Gliese-Jahriess number of the primary
                                   component of the system (gj_system_primary)
  28- 46  I19   ---       GaiaDR3 ?=- Source identifier in Gaia EDR3
                                   (gaia_edr3_id)
  48- 53  I6    ---       HIP     ?=- Hipparcos identifier (hip_id)
  55- 74 F20.16 deg       RAdeg   ?=- Right ascension (J2000) at Ep=Epoch (ra)
  76- 98 F23.19 deg       DEdeg   ?=- Declination (J2000) at Ep=Epoch (dec)
 100-108  F9.4  yr        Epoch   [1991.25/2017.97]?=- Reference epoch for
                                   coordinates (epoch)
 110-128  A19   ---     r_pos     Source of the position (coordinates_bibcode)
 130-148 F19.15 mas       plx     ?=- Absolute trigonometric parallax (parallax)
 150-162 F13.10 mas     e_plx     ?=- Error in parallax (parallax_error)
 164-182  A19   ---     r_plx     Source of the parallax (parallax_bibcode)
 184-206 F23.17 mas/yr    pmRA    ?=- Proper motion in right ascension
                                   (d(RAcosDE)/dt) (pmra)
 208-228 F21.18 mas/yr  e_pmRA    ?=- Error in pmRA (pmra_error)
 230-252 F23.17 mas/yr    pmDE    ?=- Proper motion in declination (pmdec)
 254-275 F22.18 mas/yr  e_pmDE    ?=- Error of pmDE (pmdec_error)
 277-295  A19   ---     r_pmRA    Source of the proper motion (pm_bibcode)
 297-319 F23.18 km/s      RV      ?=- Spectroscopic radial velocity (rv)
 321-340 F20.17 km/s    e_RV      ?=- Error in RV (rv_error)
 342-360  A19   ---     r_RV      Source of the radial velocity (rv_bibcode)
 362-371  F10.7 mag       Gmag    ?=- G band mean magnitude (corrected) (g_mag)
 373-393 E21.15 mag     e_Gmag    ?=- Error in Gmag (g_mag_error)
 395-404  F10.7 mag       BPmag   ?=- Gaia eDR3 integrated BP mean magnitude
                                   (bp_mag)
 406-426 E21.15 mag     e_BPmag   ?=- Error in BPmag (bp_mag_error)
 428-437  F10.7 mag       RPmag   ?=- Gaia eDR3 integrated RP mean magnitude
                                   (rp_mag)
 439-459 E21.15 mag     e_RPmag   ?=- Error in RPmag (rp_mag_error)
 461-480 F20.17 mag       GHIPmag ?=- Hipparcos Hp magnitude converted to the
                                   G band (g_mag_from_hip)
 482-500 F19.17 mag     e_GHIPmag ?=- Error in GHIPmag (g_mag_from_hip_error)
 502-522 F21.18 mag       G-RPHIP ?=- G-RP colour computed from Hipparcos
                                   V and I (g_rp_from_hip)
 524-542 F19.17 mag     e_G-RPHIP ?=- Error in G-RPHIP (g_rp_from_hip_error)
 544-555  F12.8 mag       Gmagr   ?=- Resulting (e.g., deblended) G band
                                   magnitude (g_mag_resulting)
 557-577 E21.15 mag     e_Gmagr   ?=- Error in Gmagr (g_mag_resulting_error)
 579-599 F21.18 mag       (G-RP)r ?=- Resulting (e.g., deblended) G-RP colour
                                   (g_rp_resulting)
 601-621 E21.15 mag     e_(G-RP)r ?=- Error in (G-RP)r (g_rp_resulting_error)
     623  I1    ---     f_(G-RP)r [0/2]?=- Flag on (G-RP)r
                                   (g_rp_resulting_flag) (1)
 625-631  F7.3  mag       Jmag    ?=- 2MASS J band magnitude (j_mag)
 633-637  F5.3  mag     e_Jmag    ?=- Error in Jmag (j_mag_error)
 639-645  F7.3  mag       Hmag    ?=- 2MASS H band magnitude (h_mag)
 647-651  F5.3  mag     e_Hmag    ?=- Error in Hmag (h_mag_error)
 653-659  F7.3  mag       Ksmag   ?=- 2MASS Ks band magnitude (k_mag)
 661-665  F5.3  mag     e_Ksmag   ?=- Error in Ksmag (k_mag_error)
 667-685  A19   ---     r_Jmag    Source of NIR magnitudes (jhk_mag_bibcode)
 687-693  F7.3  mag       W1mag   ?=- WISE W1 band magnitude (w1_mag)
 695-699  F5.3  mag     e_W1mag   ?=- Error in W1mag (w1_mag_error)
 701-707  F7.3  mag       W2mag   ?=- WISE W2 band magnitude (w2_mag)
 709-713  F5.3  mag     e_W2mag   ?=- Error in W2mag (w2_mag_error)
 715-721  F7.3  mag       W3mag   ?=- WISE W3 band magnitude (w3_mag)
 723-727  F5.3  mag     e_W3mag   ?=- Error in W3mag (w3_mag_error)
 729-735  F7.3  mag       W4mag   ?=- WISE W4 band magnitude (w4_mag)
 737-741  F5.3  mag     e_W4mag   ?=- Error in W4mag (w4_mag_error)
 743-761  A19   ---     r_W1mag   Source of MIR magnitudes (wise_mag_bibcode)
--------------------------------------------------------------------------------
Note (1): Flag on (G-RP)r as follows:
           0 = G-RP is deblended
           1 = G-RP is uncorrected vs. eDR3
           2 = G-RP is converted from Hipparcos
--------------------------------------------------------------------------------

Acknowledgements:
    Alex Golovin, agolovin(at)lsw.uni-heidelberg.de

================================================================================
(End)                                      Patricia Vannier [CDS]    03-May-2023
