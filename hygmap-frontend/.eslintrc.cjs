module.exports = {
  root: true,
  env: { browser: true, es2020: true },
  extends: [
    'eslint:recommended',
    'plugin:@typescript-eslint/recommended',
    'plugin:react-hooks/recommended',
  ],
  ignorePatterns: ['dist', '.eslintrc.cjs'],
  parser: '@typescript-eslint/parser',
  parserOptions: {
    ecmaVersion: 'latest',
    sourceType: 'module',
  },
  plugins: ['react-refresh'],
  rules: {
    'react-refresh/only-export-components': [
      'warn',
      { allowConstantExport: true },
    ],
    // Allow unused vars prefixed with underscore
    '@typescript-eslint/no-unused-vars': [
      'warn',
      { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
    ],
    // Allow explicit any in some cases (can tighten later)
    '@typescript-eslint/no-explicit-any': 'warn',
    // The parsec/light-year factor belongs in exactly one place. This literal has been
    // scattered across components, centralised into PC_TO_LY, and then reintroduced by
    // the very commit that centralised it -- twice caught by audit, never by a tool.
    // Now it is a lint error.
    'no-restricted-syntax': [
      'error',
      {
        selector: 'Literal[value=3.26156]',
        message:
          'Do not inline the parsec/light-year factor. Import PC_TO_LY, parsecsToLightYears, ' +
          'or lightYearsToParsecs from src/domain/coordinates.',
      },
    ],
  },
  overrides: [
    {
      // The one legitimate home of the constant, plus the tests that assert its value --
      // those exist precisely to fail if the factor is ever wrong.
      files: ['src/domain/coordinates.ts', '**/*.test.ts', '**/*.test.tsx'],
      rules: { 'no-restricted-syntax': 'off' },
    },
  ],
}
