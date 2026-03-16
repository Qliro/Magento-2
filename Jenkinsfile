#!groovy

@Library('platform-jenkins-pipeline') _

pipeline {
    agent { label 'magento23' }

    stages {
        stage('Build Module') {
            steps {
                buildModule('magento2-module', nodeLabel: 'magento23')
            }
        }
        stage('Publish Package') {
            steps {
                bitbucketStatus (key: 'publish_package', name: 'Publishing Package') {
                    generateComposerPackage(moduleName:"${env.GIT_URL}")
                }
            }
        }
    }

    post {
        always {
            sendNotifications()
        }
    }
}
