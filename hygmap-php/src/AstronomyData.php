<?php
declare(strict_types=1);

/**
 * Astronomical nomenclature lookup tables
 *
 * Contains mappings for Greek letters (Bayer designations) and constellation names
 * used for parsing star catalog identifiers.
 */
class AstronomyData
{
    /**
     * Greek letter to Bayer prefix mapping
     * Maps Greek letters (full names and symbols) to their 3-letter Bayer prefixes
     *
     * @var array<string, string>
     */
    public const GREEK_LETTERS = [
        'α'=>'alp', 'alpha'=>'alp', 'alp'=>'alp',
        'β'=>'bet', 'beta'=>'bet', 'bet'=>'bet',
        'γ'=>'gam', 'gamma'=>'gam', 'gam'=>'gam',
        'δ'=>'del', 'delta'=>'del', 'del'=>'del',
        'ε'=>'eps', 'epsilon'=>'eps', 'eps'=>'eps',
        'ζ'=>'zet', 'zeta'=>'zet', 'zet'=>'zet',
        'η'=>'eta', 'eta'=>'eta',
        'θ'=>'the', 'theta'=>'the', 'the'=>'the',
        'ι'=>'iot', 'iota'=>'iot', 'iot'=>'iot',
        'κ'=>'kap', 'kappa'=>'kap', 'kap'=>'kap',
        'λ'=>'lam', 'lambda'=>'lam', 'lam'=>'lam',
        'μ'=>'mu', 'mu'=>'mu',
        'ν'=>'nu', 'nu'=>'nu',
        'ξ'=>'ksi', 'xi'=>'ksi', 'ksi'=>'ksi',
        'ο'=>'omi', 'omicron'=>'omi', 'omi'=>'omi',
        'π'=>'pi', 'pi'=>'pi',
        'ρ'=>'rho', 'rho'=>'rho',
        'σ'=>'sig', 'sigma'=>'sig', 'sig'=>'sig',
        'τ'=>'tau', 'tau'=>'tau',
        'υ'=>'ups', 'upsilon'=>'ups', 'ups'=>'ups',
        'φ'=>'phi', 'phi'=>'phi',
        'χ'=>'chi', 'chi'=>'chi',
        'ψ'=>'psi', 'psi'=>'psi',
        'ω'=>'ome', 'omega'=>'ome', 'ome'=>'ome',
    ];

    /**
     * Constellation name to 3-letter abbreviation mapping
     * Maps full constellation names (lowercase, no spaces) to standard IAU abbreviations
     *
     * @var array<string, string>
     */
    public const CONSTELLATIONS = [
        'andromeda'          => 'And',
        'antlia'             => 'Ant',
        'apus'               => 'Aps',
        'aquarius'           => 'Aqr',
        'aquila'             => 'Aql',
        'ara'                => 'Ara',
        'aries'              => 'Ari',
        'auriga'             => 'Aur',
        'bootes'             => 'Boo',
        'caelum'             => 'Cae',
        'camelopardalis'     => 'Cam',
        'cancer'             => 'Cnc',
        'canesvenatici'      => 'CVn',
        'canismajor'         => 'CMa',
        'canisminor'         => 'CMi',
        'capricornus'        => 'Cap',
        'carina'             => 'Car',
        'cassiopeia'         => 'Cas',
        'centaurus'          => 'Cen',
        'cepheus'            => 'Cep',
        'cetus'              => 'Cet',
        'chamaeleon'         => 'Cha',
        'circinus'           => 'Cir',
        'columba'            => 'Col',
        'comaberenices'      => 'Com',
        'coronaaustralis'    => 'CrA',
        'coronaborealis'     => 'CrB',
        'corvus'             => 'Crv',
        'crater'             => 'Crt',
        'crux'               => 'Cru',
        'cygnus'             => 'Cyg',
        'delphinus'          => 'Del',
        'dorado'             => 'Dor',
        'draco'              => 'Dra',
        'equuleus'           => 'Equ',
        'eridanus'           => 'Eri',
        'fornax'             => 'For',
        'gemini'             => 'Gem',
        'grus'               => 'Gru',
        'hercules'           => 'Her',
        'horologium'         => 'Hor',
        'hydra'              => 'Hya',
        'hydrus'             => 'Hyi',
        'indus'              => 'Ind',
        'lacerta'            => 'Lac',
        'leo'                => 'Leo',
        'leominor'           => 'LMi',
        'lepus'              => 'Lep',
        'libra'              => 'Lib',
        'lupus'              => 'Lup',
        'lynx'               => 'Lyn',
        'lyra'               => 'Lyr',
        'mensa'              => 'Men',
        'microscopium'       => 'Mic',
        'monoceros'          => 'Mon',
        'musca'              => 'Mus',
        'norma'              => 'Nor',
        'octans'             => 'Oct',
        'ophiuchus'          => 'Oph',
        'orion'              => 'Ori',
        'pavo'               => 'Pav',
        'pegasus'            => 'Peg',
        'perseus'            => 'Per',
        'phoenix'            => 'Phe',
        'pictor'             => 'Pic',
        'piscisaustrinus'    => 'PsA',
        'pisces'             => 'Psc',
        'puppis'             => 'Pup',
        'pyxis'              => 'Pyx',
        'reticulum'          => 'Ret',
        'sagitta'            => 'Sge',
        'sagittarius'        => 'Sgr',
        'scorpius'           => 'Sco',
        'sculptor'           => 'Scl',
        'serpens'            => 'Ser',
        'sextans'            => 'Sex',
        'taurus'             => 'Tau',
        'telescopium'        => 'Tel',
        'triangulumaustrale' => 'TrA',
        'triangulum'         => 'Tri',
        'tucana'             => 'Tuc',
        'ursamajor'          => 'UMa',
        'ursaminor'          => 'UMi',
        'vela'               => 'Vel',
        'virgo'              => 'Vir',
        'volans'             => 'Vol',
        'vulpecula'          => 'Vul',
    ];
}
