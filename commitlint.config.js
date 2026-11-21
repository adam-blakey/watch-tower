export default {
    extends: ['@commitlint/config-conventional'],
    // Dependabot writes an unwrapped body of package links we can't control.
    ignores: [(message) => message.includes('Signed-off-by: dependabot[bot]')],
    rules: {
        'body-max-line-length': [2, 'always', 200],
    },
};
